#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

export MANAGE_SCRIPT="${MANAGE_SCRIPT:-$PROJECT_DIR/scripts/manage_telegram_filestore_prompt_workers.sh}"
export RUNNER_SCRIPT="${RUNNER_SCRIPT:-$PROJECT_DIR/scripts/run_telegram_filestore_prompt_worker.sh}"
export QUEUE_NAME="${QUEUE_NAME:-telegram_filestore_prompt}"
export SESSION_PREFIX="${SESSION_PREFIX:-tgp}"
export WORKER_COUNT="${WORKER_COUNT:-2}"
export WORKER_LOG_DIR="${WORKER_LOG_DIR:-$PROJECT_DIR/storage/logs/telegram_prompt_workers}"
export WATCHDOG_LOG="${WATCHDOG_LOG:-$PROJECT_DIR/storage/logs/tg_prompt_worker_watchdog.log}"
export CRON_FILE="${CRON_FILE:-/etc/cron.d/blog_telegram_filestore_prompt_workers}"

exec "$PROJECT_DIR/scripts/manage_telegram_filestore_workers.sh" "$@"
