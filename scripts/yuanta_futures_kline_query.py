from __future__ import annotations

from argparse import ArgumentParser
from datetime import datetime, timedelta, timezone
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


def dotnet_datetime(value) -> datetime | None:
    try:
        return datetime(value.Year, value.Month, value.Day, value.Hour, value.Minute, value.Second)
    except Exception:
        try:
            return datetime.fromisoformat(str(value).replace("/", "-"))
        except Exception:
            return None


def parse_args():
    parser = ArgumentParser(description="Query Yuanta Spark API futures K-line data.")
    parser.add_argument("--from", dest="from_date", required=True)
    parser.add_argument("--to", dest="to_date", required=True)
    parser.add_argument("--symbol", default=os.environ.get("YUANTA_FUTURES_KLINE_SYMBOL", "TXFPM1"))
    parser.add_argument("--interval", default=os.environ.get("YUANTA_FUTURES_KLINE_INTERVAL", "daily"))
    parser.add_argument("--market", default=os.environ.get("YUANTA_FUTURES_KLINE_MARKET", "TAIFEX"))
    parser.add_argument("--last-count", type=int, default=int(os.environ.get("YUANTA_FUTURES_TICK_LAST_COUNT", "6000")))
    parser.add_argument("--subscribe-seconds", type=float, default=float(os.environ.get("YUANTA_FUTURES_SUBSCRIBE_SECONDS", "6")))
    return parser.parse_args()


def session_start(timestamp: datetime) -> datetime | None:
    time_text = timestamp.strftime("%H:%M")
    if "08:45" <= time_text < "13:45":
        return timestamp.replace(hour=8, minute=45, second=0, microsecond=0)
    if time_text >= "15:00":
        return timestamp.replace(hour=15, minute=0, second=0, microsecond=0)
    if time_text < "05:00":
        previous = timestamp - timedelta(days=1)
        return previous.replace(hour=15, minute=0, second=0, microsecond=0)
    return None


def bar_started_at(timestamp: datetime, interval_key: str) -> datetime | None:
    if interval_key == "daily":
        return timestamp.replace(hour=0, minute=0, second=0, microsecond=0)

    interval_minutes = int(interval_key.rstrip("m"))
    start = session_start(timestamp)
    if start is None:
        return None

    elapsed_seconds = int((timestamp - start).total_seconds())
    if elapsed_seconds < 0:
        return None

    return start + timedelta(minutes=(elapsed_seconds // (interval_minutes * 60)) * interval_minutes)


def aggregate_ticks(ticks: list[dict], interval_key: str, from_date: str, to_date: str, quality: str) -> list[dict]:
    rows: dict[str, dict] = {}
    from_day = datetime.fromisoformat(from_date.replace("/", "-")).date()
    to_day = datetime.fromisoformat(to_date.replace("/", "-")).date()

    for tick in sorted(ticks, key=lambda item: item["timestamp"]):
        timestamp = tick["timestamp"]
        if timestamp.date() < from_day or timestamp.date() > to_day:
            continue

        started_at = bar_started_at(timestamp, interval_key)
        if started_at is None:
            continue

        key = started_at.strftime("%Y-%m-%d %H:%M:%S")
        price = float(tick["price"])
        if key not in rows:
            rows[key] = {
                "timestamp": key,
                "date": started_at.strftime("%Y-%m-%d"),
                "open": price,
                "high": price,
                "low": price,
                "close": price,
                "volume": 0,
                "quality": quality,
            }

        rows[key]["high"] = max(float(rows[key]["high"]), price)
        rows[key]["low"] = min(float(rows[key]["low"]), price)
        rows[key]["close"] = price
        rows[key]["volume"] += int(tick["volume"])

    return list(rows.values())


def stock_tick_result_to_tick(result, trade_date: datetime) -> dict | None:
    try:
        tick_time = result.Time
        timestamp = trade_date.replace(
            hour=int(tick_time.bytHour),
            minute=int(tick_time.bytMin),
            second=int(tick_time.bytSec),
            microsecond=int(tick_time.ushtMSec) * 1000,
        )
        price = float(result.DealPrice)
        if price <= 0:
            return None

        return {
            "timestamp": timestamp,
            "price": price,
            "volume": int(result.DealVol),
        }
    except Exception:
        return None


def subscribe_taifex_ticks(api, account, market, symbol, lang, seconds: float) -> list[dict]:
    from System.Collections.Generic import List
    from YuantaOneAPI import StockTick

    ticks = []
    taipei_today = datetime.utcnow() + timedelta(hours=8)

    def on_subscription(intMark, dwIndex, strIndex, objHandle, objValue):
        if int(intMark) != 2 or str(strIndex) != "SubscribeStockTick":
            return
        tick = stock_tick_result_to_tick(objValue, taipei_today)
        if tick is not None:
            ticks.append(tick)

    stocktick_list = List[StockTick]()
    stocktick = StockTick()
    stocktick.MarketType = market
    stocktick.StockCode = symbol
    stocktick_list.Add(stocktick)

    api.OnResponse += on_subscription
    try:
        api.SubscribeStockTick(account, stocktick_list, lang)
        time.sleep(max(1.0, seconds))
        try:
            api.UnSubscribeStockTick(account, stocktick_list, lang)
        except Exception:
            pass
    finally:
        try:
            api.OnResponse -= on_subscription
        except Exception:
            pass

    return ticks


def query_taifex_ticks(api, account, market, symbol, select_type, lang, last_count: int, subscribe_seconds: float):
    try:
        value = response_value(
            api.GetStkTickDetailSync(
                account,
                market,
                symbol,
                select_type.區間查詢,
                "000000",
                "235959",
                0,
                lang,
            ),
            "GetStkTickDetail",
        )
        quality = "tick_detail_range"
    except Exception:
        try:
            value = response_value(
                api.GetStkTickDetailSync(
                    account,
                    market,
                    symbol,
                    select_type.最後筆數,
                    "",
                    "",
                    max(1, last_count),
                    lang,
                ),
                "GetStkTickDetail",
            )
            quality = "tick_detail_last_count"
        except Exception:
            ticks = subscribe_taifex_ticks(api, account, market, symbol, lang, subscribe_seconds)
            return ticks, "subscription_snapshot"

    tick_list = getattr(value, "StickDetailList", [])
    ticks = []
    for index in range(tick_list.Count):
        item = tick_list[index]
        timestamp = dotnet_datetime(item.TimeStamp)
        if timestamp is None:
            continue

        price = float(item.DealPrice)
        if price <= 0:
            continue

        ticks.append({
            "timestamp": timestamp,
            "price": price,
            "volume": int(item.DealVol),
        })

    return ticks, quality


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
        from YuantaOneAPI import (
            YuantaSparkAPITrader,
            enumEnvironmentMode,
            enumLangType,
            enumMarketType,
            enumStkTickSelectType,
            KLineType,
        )

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

        if args.market.upper() == "TAIFEX":
            ticks, quality = query_taifex_ticks(
                api,
                account,
                market,
                args.symbol,
                enumStkTickSelectType,
                enumLangType.UTF8,
                args.last_count,
                args.subscribe_seconds,
            )
            rows = aggregate_ticks(ticks, interval_key, args.from_date, args.to_date, quality)
            source = "yuanta_spark_api_taifex_tick_aggregate"
        else:
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
            source = "yuanta_spark_api_kline"

        print(json.dumps({
            "queried_at": datetime.now(timezone.utc).isoformat(),
            "provider": source,
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
