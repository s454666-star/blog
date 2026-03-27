#!/usr/bin/env bash

set -uo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
SESSION_NAME="${1:-}"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
QUEUE_NAME="${QUEUE_NAME:-telegram_filestore}"
LOG_DIR="${LOG_DIR:-$PROJECT_DIR/storage/logs/telegram_workers}"
RESTART_DELAY_SECONDS="${RESTART_DELAY_SECONDS:-5}"
MAX_JOBS="${MAX_JOBS:-250}"
MAX_TIME="${MAX_TIME:-3600}"
MEMORY_LIMIT_MB="${MEMORY_LIMIT_MB:-512}"

if [[ -z "$SESSION_NAME" ]]; then
    echo "usage: $0 <session-name>" >&2
    exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
    echo "php binary not found: $PHP_BIN" >&2
    exit 1
fi

if [[ ! -f "$PROJECT_DIR/artisan" || ! -f "$PROJECT_DIR/vendor/autoload.php" ]]; then
    echo "laravel bootstrap files not found under $PROJECT_DIR" >&2
    exit 1
fi

mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/${SESSION_NAME}.log"

cd "$PROJECT_DIR" || exit 1

while true; do
    printf '%s [%s] worker-start queue=%s connection=%s\n' \
        "$(date '+%F %T')" \
        "$SESSION_NAME" \
        "$QUEUE_NAME" \
        "$QUEUE_CONNECTION" >>"$LOG_FILE"

    "$PHP_BIN" artisan queue:work "$QUEUE_CONNECTION" \
        --queue="$QUEUE_NAME" \
        --sleep=1 \
        --tries=5 \
        --timeout=900 \
        --max-jobs="$MAX_JOBS" \
        --max-time="$MAX_TIME" \
        --memory="$MEMORY_LIMIT_MB" \
        -v >>"$LOG_FILE" 2>&1

    EXIT_CODE=$?

    printf '%s [%s] worker-exit code=%s restart_in=%ss\n' \
        "$(date '+%F %T')" \
        "$SESSION_NAME" \
        "$EXIT_CODE" \
        "$RESTART_DELAY_SECONDS" >>"$LOG_FILE"

    sleep "$RESTART_DELAY_SECONDS"
done
