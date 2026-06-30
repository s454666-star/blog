from __future__ import annotations

from configparser import ConfigParser
from datetime import datetime, timezone
from pathlib import Path
import json
import os
import sys
import traceback

import esun_trade.sdk as sdk_module
from esun_trade.sdk import SDK


REQUIRED_ENV = [
    "ESUN_API_ENTRY",
    "ESUN_ACCOUNT",
    "ESUN_CERT_PATH",
    "ESUN_API_KEY",
    "ESUN_API_SECRET",
    "ESUN_ACCOUNT_PASSWORD",
    "ESUN_CERT_PASSWORD",
]


def fail(message: str, exit_code: int = 1) -> int:
    print(json.dumps({"error": message}, ensure_ascii=True), file=sys.stderr)
    return exit_code


def optional_call(label: str, callback):
    try:
        return callback(), None
    except Exception as exc:
        return None, {
            "label": label,
            "error": f"{type(exc).__name__}: {exc}",
        }


def main() -> int:
    missing = [name for name in REQUIRED_ENV if not os.environ.get(name)]
    if missing:
        return fail("Missing required environment variables: " + ", ".join(missing), 2)

    cert_path = Path(os.environ["ESUN_CERT_PATH"]).expanduser()
    if not cert_path.exists():
        return fail("Certificate file does not exist.", 3)

    config = ConfigParser()
    config["Core"] = {"Entry": os.environ["ESUN_API_ENTRY"]}
    config["Cert"] = {"Path": str(cert_path)}
    config["Api"] = {
        "Key": os.environ["ESUN_API_KEY"],
        "Secret": os.environ["ESUN_API_SECRET"],
    }
    config["User"] = {"Account": os.environ["ESUN_ACCOUNT"]}

    def load_credentials(_: str) -> dict[str, str]:
        return {
            "account_password": os.environ["ESUN_ACCOUNT_PASSWORD"],
            "cert_password": os.environ["ESUN_CERT_PASSWORD"],
        }

    sdk_module.load_credentials = load_credentials

    try:
        sdk = SDK(config)
        sdk.login()
        inventories = sdk.get_inventories()
    except Exception as exc:
        print(json.dumps({
            "error": f"{type(exc).__name__}: {exc}",
            "trace": traceback.format_exc(limit=3),
        }, ensure_ascii=True), file=sys.stderr)
        return 1

    warnings = []
    balance, warning = optional_call("balance", sdk.get_balance)
    if warning:
        warnings.append(warning)

    settlements, warning = optional_call("settlements", sdk.get_settlements)
    if warning:
        warnings.append(warning)

    trade_status, warning = optional_call("trade_status", sdk.get_trade_status)
    if warning:
        warnings.append(warning)

    today = os.environ.get("ESUN_TODAY_DATE", "").strip()
    today_transactions_history = []
    today_transactions = []
    if today:
        today_transactions_history, warning = optional_call(
            "today_transactions_by_date",
            lambda: sdk.get_transactions_by_date(today, today),
        )
        if warning:
            warnings.append(warning)
            today_transactions_history = []

        today_transactions, warning = optional_call(
            "today_transactions_range",
            lambda: sdk.get_transactions("0d"),
        )
        if warning:
            warnings.append(warning)
            today_transactions = []

    print(json.dumps({
        "queried_at": datetime.now(timezone.utc).isoformat(),
        "inventories": inventories,
        "balance": balance if isinstance(balance, dict) else {},
        "trade_status": trade_status if isinstance(trade_status, dict) else {},
        "settlements": settlements if isinstance(settlements, list) else [],
        "today_transactions_history": today_transactions_history if isinstance(today_transactions_history, list) else [],
        "today_transactions": today_transactions if isinstance(today_transactions, list) else [],
        "warnings": warnings,
    }, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
