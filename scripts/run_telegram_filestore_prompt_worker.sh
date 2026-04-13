#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

export QUEUE_NAME="${QUEUE_NAME:-telegram_filestore_prompt}"
export LOG_DIR="${LOG_DIR:-$PROJECT_DIR/storage/logs/telegram_prompt_workers}"

exec "$PROJECT_DIR/scripts/run_telegram_filestore_worker.sh" "$@"
