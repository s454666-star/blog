from __future__ import annotations

from argparse import ArgumentParser
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

KLINE_TYPES = {
    "1": 0,
    "1m": 0,
    "5": 1,
    "5m": 1,
    "15": 2,
    "15m": 2,
    "30": 3,
    "30m": 3,
    "60": 4,
    "60m": 4,
    "daily": 11,
    "day": 11,
    "d": 11,
}


def fail(message: str, exit_code: int = 1) -> int:
    print(json.dumps({"error": message}, ensure_ascii=True), file=sys.stderr)
    return exit_code


def response_value(response, label: str):
    success = bool(getattr(response, "Success", False))
    if not success:
        message = str(getattr(response, "ErrorMessage", "")) or f"{label} failed"
        raise RuntimeError(message)

    return getattr(response, "objValue")


def dotnet_datetime_payload(value) -> dict:
    try:
        return {
            "timestamp": f"{value.Year:04d}-{value.Month:02d}-{value.Day:02d} {value.Hour:02d}:{value.Minute:02d}:{value.Second:02d}",
            "date": f"{value.Year:04d}-{value.Month:02d}-{value.Day:02d}",
        }
    except Exception:
        text = str(value)
        return {
            "timestamp": text,
            "date": text[:10],
        }


def parse_args():
    parser = ArgumentParser(description="Query Yuanta Spark API futures K-line data.")
    parser.add_argument("--from", dest="from_date", required=True)
    parser.add_argument("--to", dest="to_date", required=True)
    parser.add_argument("--symbol", default=os.environ.get("YUANTA_FUTURES_KLINE_SYMBOL", "TXFPM1"))
    parser.add_argument("--interval", default=os.environ.get("YUANTA_FUTURES_KLINE_INTERVAL", "daily"))
    parser.add_argument("--market", default=os.environ.get("YUANTA_FUTURES_KLINE_MARKET", "TAIFEX"))
    return parser.parse_args()


def main() -> int:
    args = parse_args()
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

    interval_key = args.interval.strip().lower()
    if interval_key not in KLINE_TYPES:
        return fail("Unsupported K-line interval.", 6)

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
        from YuantaOneAPI import YuantaSparkAPITrader, enumEnvironmentMode, enumLangType, enumMarketType, KLineType

        log_path = Path(os.environ.get("YUANTA_LOG_PATH", Path.cwd() / "storage" / "logs" / "yuanta"))
        log_path.mkdir(parents=True, exist_ok=True)
        api = YuantaSparkAPITrader(str(log_path))
        login_events = []

        def on_response(intMark, dwIndex, strIndex, objHandle, objValue):
            if str(strIndex) == "Login":
                login_events.append(str(objValue))

        api.OnResponse += on_response

        mode_name = os.environ["YUANTA_API_ENVIRONMENT"].upper()
        mode = getattr(enumEnvironmentMode, "PROD" if mode_name == "PROD" else "UAT")
        market = getattr(enumMarketType, args.market.upper())
        kline_type = KLineType(KLINE_TYPES[interval_key])
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
            return fail("Yuanta login call was rejected before receiving a response.", 7)

        value = response_value(
            api.GetKLineSync(
                account,
                kline_type,
                market,
                args.symbol,
                args.from_date.replace("-", "/"),
                args.to_date.replace("-", "/"),
                enumLangType.UTF8,
            ),
            "GetKLine",
        )

        rows = []
        kline_list = getattr(value, "KLineList", [])
        for index in range(kline_list.Count):
            item = kline_list[index]
            timestamp = dotnet_datetime_payload(item.TimeStamp)
            rows.append({
                **timestamp,
                "open": float(item.OpenPrice),
                "high": float(item.HighPrice),
                "low": float(item.LowPrice),
                "close": float(item.ClosePrice),
                "volume": int(item.DealVol),
            })

        print(json.dumps({
            "queried_at": datetime.now(timezone.utc).isoformat(),
            "provider": "yuanta_spark_api",
            "market": args.market.upper(),
            "symbol": args.symbol,
            "interval": interval_key,
            "rows": rows,
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
