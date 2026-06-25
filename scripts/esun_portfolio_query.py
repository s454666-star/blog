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
        balance = sdk.get_balance()
        settlements = sdk.get_settlements()
    except Exception as exc:
        print(json.dumps({
            "error": f"{type(exc).__name__}: {exc}",
            "trace": traceback.format_exc(limit=3),
        }, ensure_ascii=True), file=sys.stderr)
        return 1

    print(json.dumps({
        "queried_at": datetime.now(timezone.utc).isoformat(),
        "inventories": inventories,
        "balance": balance,
        "settlements": settlements,
    }, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
