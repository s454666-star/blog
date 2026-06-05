#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html/blog}"
APP_USER="${APP_USER:-www-data}"
BRANCH="${BRANCH:-master}"

log() {
  echo "[$(date '+%F %T')] $*"
}

run_as_app() {
  sudo -u "$APP_USER" -H "$@"
}

log "Checking ${BRANCH}"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/.git"
run_as_app git -C "$APP_DIR" config core.fileMode false

tracked_changes="$(run_as_app git -C "$APP_DIR" status --porcelain --untracked-files=no)"
if [ -n "$tracked_changes" ]; then
  log "Skipped: tracked changes present on server"
  echo "$tracked_changes"
  exit 0
fi

run_as_app git -C "$APP_DIR" fetch origin "$BRANCH" --quiet
local_sha="$(run_as_app git -C "$APP_DIR" rev-parse HEAD)"
remote_ref="origin/$BRANCH"
remote_sha="$(run_as_app git -C "$APP_DIR" rev-parse "$remote_ref")"

if [ "$local_sha" = "$remote_sha" ]; then
  log "No changes"
  exit 0
fi

if ! run_as_app git -C "$APP_DIR" merge-base --is-ancestor "$local_sha" "$remote_sha"; then
  if run_as_app git -C "$APP_DIR" merge-base --is-ancestor "$remote_sha" "$local_sha"; then
    log "Skipped: server is ahead of ${remote_ref}; push or reconcile the live-only commit first"
  else
    log "Skipped: server and ${remote_ref} have diverged; manual reconciliation required"
  fi

  log "server=${local_sha}"
  log "${remote_ref}=${remote_sha}"
  exit 0
fi

changed_files="$(run_as_app git -C "$APP_DIR" diff --name-only "$local_sha" "$remote_sha")"

log "Deploying ${local_sha} -> ${remote_sha}"
run_as_app git -C "$APP_DIR" merge --ff-only "$remote_ref"

mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/bootstrap/cache"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} +

if printf '%s\n' "$changed_files" | grep -Eq '^(composer\.json|composer\.lock)$'; then
  log "Running composer install"
  run_as_app env COMPOSER_HOME=/tmp/composer-www-data composer -d "$APP_DIR" install --no-dev --optimize-autoloader --no-interaction
fi

log "Clearing Laravel cache"
run_as_app php "$APP_DIR/artisan" optimize:clear > /dev/null

log "Deploy complete"
