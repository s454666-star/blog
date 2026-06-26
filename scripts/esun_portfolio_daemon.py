from __future__ import annotations

from configparser import ConfigParser
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse
import json
import os
import re
import sys
import threading
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

DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def load_dotenv() -> None:
    path = os.environ.get("ESUN_ENV_PATH", "").strip()
    if not path:
        return

    env_path = Path(path).expanduser()
    if not env_path.exists():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key.startswith("ESUN_") and key not in os.environ:
            os.environ[key] = value


def build_config() -> ConfigParser:
    missing = [name for name in REQUIRED_ENV if not os.environ.get(name)]
    if missing:
        raise RuntimeError("Missing required environment variables: " + ", ".join(missing))

    cert_path = Path(os.environ["ESUN_CERT_PATH"]).expanduser()
    if not cert_path.exists():
        raise RuntimeError("Certificate file does not exist.")

    config = ConfigParser()
    config["Core"] = {"Entry": os.environ["ESUN_API_ENTRY"]}
    config["Cert"] = {"Path": str(cert_path)}
    config["Api"] = {
        "Key": os.environ["ESUN_API_KEY"],
        "Secret": os.environ["ESUN_API_SECRET"],
    }
    config["User"] = {"Account": os.environ["ESUN_ACCOUNT"]}

    return config


def install_credentials_loader() -> None:
    def load_credentials(_: str) -> dict[str, str]:
        return {
            "account_password": os.environ["ESUN_ACCOUNT_PASSWORD"],
            "cert_password": os.environ["ESUN_CERT_PASSWORD"],
        }

    sdk_module.load_credentials = load_credentials


def optional_call(label: str, callback):
    try:
        return callback(), None
    except Exception as exc:
        return None, {
            "label": label,
            "error": f"{type(exc).__name__}: {exc}",
        }


def is_relogin_candidate(message: str) -> bool:
    lowered = message.lower()
    if "agr" in lowered:
        return False

    return any(term in lowered for term in [
        "login",
        "token",
        "unauthorized",
        "forbidden",
        "未登入",
        "登入",
    ])


class EsunSession:
    def __init__(self) -> None:
        self._lock = threading.RLock()
        self._sdk: SDK | None = None
        self.login_count = 0
        self.login_at: str | None = None

    def health(self) -> dict[str, object]:
        with self._lock:
            return {
                "ok": True,
                "logged_in": self._sdk is not None,
                "login_count": self.login_count,
                "login_at": self.login_at,
                "now": datetime.now(timezone.utc).isoformat(),
            }

    def _login(self) -> SDK:
        install_credentials_loader()
        sdk = SDK(build_config())
        sdk.login()
        self._sdk = sdk
        self.login_count += 1
        self.login_at = datetime.now(timezone.utc).isoformat()

        return sdk

    def _sdk_or_login(self) -> SDK:
        if self._sdk is None:
            return self._login()

        return self._sdk

    def call(self, callback, retry_login: bool = True):
        with self._lock:
            sdk = self._sdk_or_login()
            try:
                return callback(sdk)
            except Exception as exc:
                message = str(exc)
                if retry_login and is_relogin_candidate(message):
                    self._sdk = None
                    sdk = self._login()
                    return callback(sdk)

                raise

    def portfolio(self, today: str | None) -> dict[str, object]:
        inventories = self.call(lambda sdk: sdk.get_inventories())
        warnings = []

        balance, warning = optional_call("balance", lambda: self.call(lambda sdk: sdk.get_balance(), False))
        if warning:
            warnings.append(warning)

        settlements, warning = optional_call("settlements", lambda: self.call(lambda sdk: sdk.get_settlements(), False))
        if warning:
            warnings.append(warning)

        today_history = []
        today_transactions = []
        if today:
            today_history, warning = optional_call(
                "today_transactions_by_date",
                lambda: self.call(lambda sdk: sdk.get_transactions_by_date(today, today), False),
            )
            if warning:
                warnings.append(warning)
                today_history = []

            today_transactions, warning = optional_call(
                "today_transactions_range",
                lambda: self.call(lambda sdk: sdk.get_transactions("0d"), False),
            )
            if warning:
                warnings.append(warning)
                today_transactions = []

        return {
            "queried_at": datetime.now(timezone.utc).isoformat(),
            "inventories": inventories,
            "balance": balance if isinstance(balance, dict) else {},
            "settlements": settlements if isinstance(settlements, list) else [],
            "today_transactions_history": today_history if isinstance(today_history, list) else [],
            "today_transactions": today_transactions if isinstance(today_transactions, list) else [],
            "warnings": warnings,
            "daemon": self.health(),
        }

    def transactions(self, start: str, end: str, query_range: str | None) -> dict[str, object]:
        if query_range:
            transactions = self.call(lambda sdk: sdk.get_transactions(query_range))
        else:
            transactions = self.call(lambda sdk: sdk.get_transactions_by_date(start, end))

        return {
            "queried_at": datetime.now(timezone.utc).isoformat(),
            "start": start,
            "end": end,
            "range": query_range,
            "transactions": transactions,
            "daemon": self.health(),
        }


SESSION = EsunSession()


class Handler(BaseHTTPRequestHandler):
    server_version = "EsunPortfolioDaemon/1.0"

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        query = parse_qs(parsed.query)

        try:
            if parsed.path == "/health":
                self.respond(200, SESSION.health())
                return

            if parsed.path == "/portfolio":
                today = first(query, "today")
                if today and not DATE_RE.match(today):
                    self.respond(400, {"error": "today must be YYYY-MM-DD"})
                    return

                self.respond(200, SESSION.portfolio(today))
                return

            if parsed.path == "/transactions":
                start = first(query, "start")
                end = first(query, "end")
                query_range = first(query, "range")
                if not start or not end or not DATE_RE.match(start) or not DATE_RE.match(end):
                    self.respond(400, {"error": "start and end must be YYYY-MM-DD"})
                    return

                self.respond(200, SESSION.transactions(start, end, query_range))
                return

            self.respond(404, {"error": "not found"})
        except Exception as exc:
            self.respond(503, {
                "error": f"{type(exc).__name__}: {exc}",
                "trace": traceback.format_exc(limit=3),
                "daemon": SESSION.health(),
            })

    def log_message(self, fmt: str, *args) -> None:
        print(
            "%s - %s" % (self.log_date_time_string(), fmt % args),
            file=sys.stderr,
            flush=True,
        )

    def respond(self, status: int, payload: dict[str, object]) -> None:
        body = json.dumps(payload, ensure_ascii=True).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def first(query: dict[str, list[str]], key: str) -> str | None:
    values = query.get(key) or []
    return values[0].strip() if values else None


def main() -> int:
    load_dotenv()
    host = os.environ.get("ESUN_PORTFOLIO_DAEMON_HOST", "127.0.0.1")
    port = int(os.environ.get("ESUN_PORTFOLIO_DAEMON_PORT", "8765"))
    server = ThreadingHTTPServer((host, port), Handler)
    print(f"Serving E.SUN portfolio daemon on http://{host}:{port}", flush=True)
    server.serve_forever()

    return 0


if __name__ == "__main__":
    sys.exit(main())
