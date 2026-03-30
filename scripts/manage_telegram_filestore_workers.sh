#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
RUNNER_SCRIPT="${RUNNER_SCRIPT:-$PROJECT_DIR/scripts/run_telegram_filestore_worker.sh}"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
SCREEN_BIN="${SCREEN_BIN:-$(command -v screen)}"
PGREP_BIN="${PGREP_BIN:-$(command -v pgrep)}"
QUEUE_NAME="${QUEUE_NAME:-telegram_filestore}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
SESSION_PREFIX="${SESSION_PREFIX:-tg}"
WORKER_COUNT="${WORKER_COUNT:-15}"
WORKER_LOG_DIR="${WORKER_LOG_DIR:-$PROJECT_DIR/storage/logs/telegram_workers}"
WATCHDOG_LOG="${WATCHDOG_LOG:-$PROJECT_DIR/storage/logs/tg_worker_watchdog.log}"
PENDING_STALE_SECONDS="${PENDING_STALE_SECONDS:-600}"
RESERVED_STALE_SECONDS="${RESERVED_STALE_SECONDS:-1200}"
WORKER_LOG_STALE_SECONDS="${WORKER_LOG_STALE_SECONDS:-600}"
STARTUP_GRACE_SECONDS="${STARTUP_GRACE_SECONDS:-120}"
STOP_GRACE_SECONDS="${STOP_GRACE_SECONDS:-2}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/blog_telegram_filestore_workers}"

ACTION="${1:-status}"
TARGET="${2:-all}"

log_line() {
    printf '%s %s\n' "$(date '+%F %T')" "$*"
}

die() {
    log_line "ERROR: $*"
    exit 1
}

require_root() {
    if [[ "$(id -u)" -ne 0 ]]; then
        die "run as root first, for example: sudo su - root"
    fi
}

ensure_prerequisites() {
    [[ -x "$SCREEN_BIN" ]] || die "screen not found"
    [[ -x "$PHP_BIN" ]] || die "php not found"
    [[ -x "$PGREP_BIN" ]] || die "pgrep not found"
    [[ -x "$RUNNER_SCRIPT" ]] || die "runner script not executable: $RUNNER_SCRIPT"
    [[ -f "$PROJECT_DIR/artisan" ]] || die "artisan not found under $PROJECT_DIR"
    [[ -f "$PROJECT_DIR/vendor/autoload.php" ]] || die "vendor/autoload.php not found under $PROJECT_DIR"

    mkdir -p "$WORKER_LOG_DIR" "$(dirname "$WATCHDOG_LOG")"
}

session_names() {
    local i
    for ((i = 1; i <= WORKER_COUNT; i++)); do
        printf '%s%s\n' "$SESSION_PREFIX" "$i"
    done
}

resolve_targets() {
    if [[ "$TARGET" == "all" ]]; then
        session_names
    else
        printf '%s\n' "$TARGET"
    fi
}

worker_log_path() {
    printf '%s/%s.log' "$WORKER_LOG_DIR" "$1"
}

screen_exists() {
    "$SCREEN_BIN" -ls 2>/dev/null | grep -Eq "[[:space:]][0-9]+\\.$1[[:space:]]"
}

screen_pid() {
    "$SCREEN_BIN" -ls 2>/dev/null | awk -v session="$1" '$1 ~ ("\\." session "$") { split($1, parts, "."); print parts[1]; exit }' || true
}

queue_worker_pid_for_session() {
    local session="$1"
    local root_pid
    local -a frontier next
    local parent child args

    root_pid="$(screen_pid "$session")"
    [[ -n "$root_pid" ]] || return 0

    frontier=("$root_pid")

    while ((${#frontier[@]} > 0)); do
        next=()

        for parent in "${frontier[@]}"; do
            while read -r child; do
                [[ -n "$child" ]] || continue
                args="$(ps -p "$child" -o args= 2>/dev/null || true)"

                if [[ "$args" == *"artisan queue:work"* ]] && [[ "$args" == *"$QUEUE_CONNECTION"* ]] && [[ "$args" == *"--queue=$QUEUE_NAME"* ]]; then
                    printf '%s\n' "$child"
                    return 0
                fi

                next+=("$child")
            done < <("$PGREP_BIN" -P "$parent" || true)
        done

        frontier=("${next[@]}")
    done
}

log_age_seconds() {
    local file="$1"
    local now mtime

    [[ -f "$file" ]] || {
        printf '%s\n' "-1"
        return 0
    }

    now="$(date +%s)"
    mtime="$(stat -c %Y "$file")"
    printf '%s\n' "$((now - mtime))"
}

read_queue_metrics() {
    local output

    output="$(PROJECT_DIR="$PROJECT_DIR" QUEUE_NAME="$QUEUE_NAME" "$PHP_BIN" <<'PHP'
<?php
$projectDir = getenv('PROJECT_DIR');
$queueName = getenv('QUEUE_NAME');
$now = time();

require $projectDir . '/vendor/autoload.php';
$app = require $projectDir . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pending = \Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNull('reserved_at')
    ->selectRaw('COUNT(*) as aggregate_count, MIN(available_at) as oldest_timestamp')
    ->first();

$reserved = \Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNotNull('reserved_at')
    ->selectRaw('COUNT(*) as aggregate_count, MIN(reserved_at) as oldest_timestamp')
    ->first();

$failed = \Illuminate\Support\Facades\DB::table('failed_jobs')
    ->selectRaw('COUNT(*) as aggregate_count')
    ->first();

$pendingCount = (int) ($pending->aggregate_count ?? 0);
$pendingAge = ($pendingCount > 0 && $pending->oldest_timestamp !== null)
    ? max(0, $now - (int) $pending->oldest_timestamp)
    : 0;

$reservedCount = (int) ($reserved->aggregate_count ?? 0);
$reservedAge = ($reservedCount > 0 && $reserved->oldest_timestamp !== null)
    ? max(0, $now - (int) $reserved->oldest_timestamp)
    : 0;

$failedCount = (int) ($failed->aggregate_count ?? 0);

printf("PENDING_COUNT=%d\n", $pendingCount);
printf("PENDING_AGE=%d\n", $pendingAge);
printf("RESERVED_COUNT=%d\n", $reservedCount);
printf("RESERVED_AGE=%d\n", $reservedAge);
printf("FAILED_COUNT=%d\n", $failedCount);
PHP
)"

    eval "$output"
}

print_status() {
    local session worker_pid log_age

    read_queue_metrics

    log_line "queue=$QUEUE_NAME pending=$PENDING_COUNT pending_age=${PENDING_AGE}s reserved=$RESERVED_COUNT reserved_age=${RESERVED_AGE}s failed=$FAILED_COUNT"

    while read -r session; do
        worker_pid="$(queue_worker_pid_for_session "$session" || true)"
        log_age="$(log_age_seconds "$(worker_log_path "$session")")"

        if screen_exists "$session"; then
            printf '%s screen=up worker_pid=%s log_age=%s\n' \
                "$session" \
                "${worker_pid:-none}" \
                "$([[ "$log_age" -ge 0 ]] && printf '%ss' "$log_age" || printf 'missing')"
        else
            printf '%s screen=down worker_pid=none log_age=%s\n' \
                "$session" \
                "$([[ "$log_age" -ge 0 ]] && printf '%ss' "$log_age" || printf 'missing')"
        fi
    done < <(session_names)
}

start_worker() {
    local session="$1"
    local launch_cmd

    if screen_exists "$session"; then
        log_line "$session already running"
        return 0
    fi

    printf -v launch_cmd 'cd %q && exec %q %q' "$PROJECT_DIR" "$RUNNER_SCRIPT" "$session"
    "$SCREEN_BIN" -dmS "$session" bash -lc "$launch_cmd"
    sleep 1

    if ! screen_exists "$session"; then
        die "failed to start $session"
    fi

    log_line "started $session"
}

stop_worker() {
    local session="$1"

    if ! screen_exists "$session"; then
        log_line "$session already stopped"
        return 0
    fi

    "$SCREEN_BIN" -S "$session" -X quit || true
    sleep "$STOP_GRACE_SECONDS"

    if screen_exists "$session"; then
        die "failed to stop $session"
    fi

    log_line "stopped $session"
}

start_targets() {
    local session
    while read -r session; do
        start_worker "$session"
    done < <(resolve_targets)
}

stop_targets() {
    local session
    while read -r session; do
        stop_worker "$session"
    done < <(resolve_targets)
}

restart_targets() {
    stop_targets
    start_targets
}

ensure_targets() {
    local session worker_pid

    while read -r session; do
        worker_pid="$(queue_worker_pid_for_session "$session" || true)"

        if ! screen_exists "$session"; then
            log_line "$session missing, starting"
            start_worker "$session"
            continue
        fi

        if [[ -z "$worker_pid" ]]; then
            log_line "$session has no queue worker process, restarting"
            stop_worker "$session"
            start_worker "$session"
        fi
    done < <(resolve_targets)
}

workers_look_stale_for_backlog() {
    local session worker_pid log_age worker_uptime

    while read -r session; do
        worker_pid="$(queue_worker_pid_for_session "$session" || true)"

        if [[ -n "$worker_pid" ]]; then
            worker_uptime="$(ps -p "$worker_pid" -o etimes= 2>/dev/null | tr -d ' ' || true)"
            if [[ -n "$worker_uptime" && "$worker_uptime" -lt "$STARTUP_GRACE_SECONDS" ]]; then
                return 1
            fi
        fi

        log_age="$(log_age_seconds "$(worker_log_path "$session")")"
        if [[ "$log_age" -ge 0 && "$log_age" -lt "$WORKER_LOG_STALE_SECONDS" ]]; then
            return 1
        fi
    done < <(session_names)

    return 0
}

run_watchdog() {
    ensure_targets
    read_queue_metrics

    if [[ "$RESERVED_COUNT" -gt 0 && "$RESERVED_AGE" -ge "$RESERVED_STALE_SECONDS" ]]; then
        log_line "reserved jobs stale: count=$RESERVED_COUNT oldest_age=${RESERVED_AGE}s, restarting all workers"
        TARGET="all"
        restart_targets
        return 0
    fi

    if [[ "$PENDING_COUNT" -gt 0 && "$PENDING_AGE" -ge "$PENDING_STALE_SECONDS" ]] && workers_look_stale_for_backlog; then
        log_line "pending backlog stale: count=$PENDING_COUNT oldest_age=${PENDING_AGE}s, restarting all workers"
        TARGET="all"
        restart_targets
        return 0
    fi

    log_line "watchdog ok pending=$PENDING_COUNT reserved=$RESERVED_COUNT failed=$FAILED_COUNT"
}

install_cron() {
    cat >"$CRON_FILE" <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

@reboot root /bin/sleep 30 && cd $PROJECT_DIR && /bin/bash $PROJECT_DIR/scripts/manage_telegram_filestore_workers.sh watchdog >> $WATCHDOG_LOG 2>&1
* * * * * root cd $PROJECT_DIR && /bin/bash $PROJECT_DIR/scripts/manage_telegram_filestore_workers.sh watchdog >> $WATCHDOG_LOG 2>&1
EOF

    chmod 644 "$CRON_FILE"
    log_line "installed cron file $CRON_FILE"
}

usage() {
    cat <<EOF
usage: $0 <status|start|stop|restart|ensure|watchdog|install-cron> [session-name|all]

examples:
  sudo su - root
  bash $0 status
  bash $0 restart all
  bash $0 stop tg3
  bash $0 install-cron
EOF
}

require_root
ensure_prerequisites

case "$ACTION" in
    status)
        print_status
        ;;
    start)
        start_targets
        ;;
    stop)
        stop_targets
        ;;
    restart)
        restart_targets
        ;;
    ensure)
        ensure_targets
        ;;
    watchdog)
        run_watchdog
        ;;
    install-cron)
        install_cron
        ;;
    *)
        usage
        exit 1
        ;;
esac
