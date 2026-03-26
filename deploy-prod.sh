#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

ENV_FILE="${ENV_FILE:-.env}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Arquivo de ambiente não encontrado: $ENV_FILE" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

echo "🚀 Subindo MedIntelligence backend em produção..."
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.prod.yml up -d --build
echo "✅ Deploy do backend concluído."

if [[ "${RUN_RUNTIME_SMOKE_TEST:-true}" == "true" ]]; then
  echo "🧪 Executando smoke test do agent-runtime..."
  python3 "$SCRIPT_DIR/agent-runtime/scripts/runtime_smoke_test.py" \
    --base-url "${RUNTIME_SMOKE_TEST_BASE_URL:-http://127.0.0.1:${RUNTIME_PORT:-8787}}" \
    --api-base-url "${API_SMOKE_TEST_BASE_URL:-http://127.0.0.1:${API_PORT:-8000}}" \
    --token "${CHATBOT_RUNTIME_SECRET:-}" \
    --timeout "${RUNTIME_SMOKE_TEST_TIMEOUT_SECONDS:-15}" \
    $([[ "${RUNTIME_SMOKE_TEST_REQUIRE_AUTH:-true}" == "true" ]] && printf '%s' '--require-auth-checks')
  echo "✅ Smoke test do agent-runtime concluído."
fi
