#!/usr/bin/env bash
set -euo pipefail

for attempt in $(seq 1 60); do
    healthy=0
    for port in 8001 8002 8003; do
        if curl --fail --silent --show-error --max-time 3 "http://127.0.0.1:${port}/bots/health" \
            | grep --quiet '"status":"ok"'; then
            healthy=$((healthy + 1))
        fi
    done

    if [[ ${healthy} -eq 3 ]]; then
        exit 0
    fi

    sleep 1
done

echo "Telegram FastAPI services did not become healthy within 60 seconds." >&2
exit 1
