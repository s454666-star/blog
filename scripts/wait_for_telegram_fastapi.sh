#!/usr/bin/env bash
set -euo pipefail

base_uris="${TELEGRAM_RESOURCE_CODE_BASE_URIS:-}"
if [[ -z "${base_uris}" && -r .env ]]; then
    base_uris="$(sed -n 's/^TELEGRAM_RESOURCE_CODE_BASE_URIS=//p' .env | tail -n 1)"
fi
base_uris="${base_uris:-http://127.0.0.1:8001,http://127.0.0.1:8002,http://127.0.0.1:8003}"
IFS=',' read -r -a account_uris <<< "${base_uris}"

for attempt in $(seq 1 60); do
    healthy=0
    total=0
    for raw_uri in "${account_uris[@]}"; do
        uri="${raw_uri%/}"
        if [[ -z "${uri}" ]]; then
            continue
        fi

        total=$((total + 1))
        if curl --fail --silent --show-error --max-time 3 "${uri}/bots/health" \
            | grep --quiet '"status":"ok"'; then
            healthy=$((healthy + 1))
        fi
    done

    if [[ ${total} -gt 0 && ${healthy} -eq ${total} ]]; then
        exit 0
    fi

    sleep 1
done

echo "Telegram FastAPI services did not become healthy within 60 seconds." >&2
exit 1
