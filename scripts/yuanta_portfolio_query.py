from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
import json
import os
import sys
import time
import traceback


REQUIRED_ENV = [
    "YUANTA_SDK_PATH",
    "YUANTA_API_ENVIRONMENT",
    "YUANTA_ACCOUNT",
    "YUANTA_PASSWORD",
    "YUANTA_PFX_PATH",
    "YUANTA_PFX_PASSWORD",
]


def fail(message: str, exit_code: int = 1) -> int:
    print(json.dumps({"error": message}, ensure_ascii=True), file=sys.stderr)
    return exit_code


def public_fields(obj, seen=None, depth: int = 0) -> dict:
    if obj is None:
        return {}
    if depth > 10:
        return {}

    if seen is None:
        seen = set()
    marker = id(obj)
    if marker in seen:
        return {}
    seen.add(marker)

    fields = obj.GetType().GetFields()
    result = {}
    for field in fields:
        if getattr(field, "IsStatic", False):
            continue
        value = field.GetValue(obj)
        result[field.Name] = convert_value(value, seen, depth + 1)

    seen.discard(marker)

    return result


def convert_value(value, seen=None, depth: int = 0):
    if value is None:
        return None
    if depth > 10:
        return str(value)

    if isinstance(value, (str, int, float, bool)):
        return value

    value_type = value.GetType() if hasattr(value, "GetType") else None
    if value_type is not None and value_type.IsEnum:
        return str(value)

    type_name = value_type.FullName if value_type is not None else ""
    if type_name and type_name.startswith("System.Collections.Generic.List"):
        return [convert_value(item, seen, depth + 1) for item in value]

    if type_name and type_name.startswith("YuantaOneAPI."):
        return public_fields(value, seen, depth + 1)

    return str(value)


def response_value(response, label: str):
    success = bool(getattr(response, "Success", False))
    if not success:
        message = str(getattr(response, "ErrorMessage", "")) or f"{label} failed"
        raise RuntimeError(message)

    return getattr(response, "objValue")


def main() -> int:
    missing = [name for name in REQUIRED_ENV if not os.environ.get(name)]
    if missing:
        return fail("Missing required environment variables: " + ", ".join(missing), 2)

    sdk_path = Path(os.environ["YUANTA_SDK_PATH"]).expanduser()
    pfx_path = Path(os.environ["YUANTA_PFX_PATH"]).expanduser()
    if not sdk_path.exists():
        return fail("Yuanta SDK path does not exist.", 3)
    if not (sdk_path / "YuantaSparkAPI.dll").exists():
        return fail("YuantaSparkAPI.dll was not found in SDK path.", 4)
    if not pfx_path.exists():
        return fail("Yuanta PFX file does not exist.", 5)

    dotnet_root = os.environ.get("YUANTA_DOTNET_ROOT")
    if dotnet_root:
        os.environ["DOTNET_ROOT"] = dotnet_root
        os.environ["PATH"] = dotnet_root + os.pathsep + os.environ.get("PATH", "")

    try:
        from pythonnet import load

        load("coreclr")
        import clr

        sys.path.append(str(sdk_path))
        if sys.platform == "win32":
            os.add_dll_directory(str(sdk_path))

        clr.AddReference("YuantaSparkAPI")
        from YuantaOneAPI import YuantaSparkAPITrader, enumEnvironmentMode, enumLangType

        api = YuantaSparkAPITrader()
        login_events = []

        def on_response(intMark, dwIndex, strIndex, objHandle, objValue):
            if str(strIndex) == "Login":
                login_events.append(public_fields(objValue))

        api.OnResponse += on_response

        mode_name = os.environ["YUANTA_API_ENVIRONMENT"].upper()
        mode = getattr(enumEnvironmentMode, "PROD" if mode_name == "PROD" else "UAT")
        lang = enumLangType.UTF8
        account = os.environ["YUANTA_ACCOUNT"]

        api.Open(mode)
        time.sleep(float(os.environ.get("YUANTA_OPEN_WAIT_SECONDS", "2")))
        accepted = bool(api.Login(
            str(pfx_path),
            os.environ["YUANTA_PFX_PASSWORD"],
            account,
            os.environ["YUANTA_PASSWORD"],
        ))
        time.sleep(float(os.environ.get("YUANTA_LOGIN_WAIT_SECONDS", "6")))

        if not accepted:
            return fail("Yuanta login call was rejected before receiving a response.", 6)

        if login_events:
            status = login_events[-1].get("LoginStatus", {})
            msg_code = str(status.get("MsgCode", ""))
            if msg_code not in {"0001", "00001"} and int(status.get("Count") or 0) <= 0:
                return fail("Yuanta login failed: " + str(status.get("MsgContent", msg_code)), 7)

        store_summary = response_value(api.GetStoreSummarySync(account, lang), "GetStoreSummary")
        bank_balance = response_value(api.GetBankBalanceSync(account, lang), "GetBankBalance")
        settlements = response_value(api.GetStkTransactionOutlaySync(account, lang), "GetStkTransactionOutlay")

        realized_start = os.environ.get("YUANTA_REALIZED_START", datetime.now().strftime("%Y/01/01"))
        realized_end = os.environ.get("YUANTA_REALIZED_END", datetime.now().strftime("%Y/%m/%d"))
        realized = response_value(
            api.GetHisRealizedGainLossSync(account, realized_start, realized_end, lang),
            "GetHisRealizedGainLoss",
        )

        store = public_fields(store_summary)
        balance = public_fields(bank_balance)
        outlay = public_fields(settlements)
        realized_payload = public_fields(realized)

        print(json.dumps({
            "queried_at": datetime.now(timezone.utc).isoformat(),
            "login": login_events[-1] if login_events else None,
            "inventories": store.get("StkStoreList") or [],
            "overseas_inventories": store.get("OVStkStoreList") or [],
            "balance": balance.get("BankBalanceList") or [],
            "settlements": outlay.get("TransactionOutlayList") or [],
            "transactions": realized_payload.get("RealizedGainLossList") or [],
        }, ensure_ascii=True))
        return 0
    except Exception as exc:
        print(json.dumps({
            "error": f"{type(exc).__name__}: {exc}",
            "trace": traceback.format_exc(limit=5),
        }, ensure_ascii=True), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
