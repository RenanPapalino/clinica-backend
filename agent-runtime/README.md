# MedIntelligence Agent Runtime

Runtime separado em Python para o chatbot do MedIntelligence.

## Objetivo

Este servico recebe mensagens do Laravel, usa LangChain para:

- memoria curta por sessao;
- consulta via tools autenticadas do Laravel;
- busca documental em RAG MySQL;
- preparacao de acoes com confirmacao;
- execucao de confirmacoes aprovadas.

## Endpoints

- `GET /health`
- `POST /chat`
- `POST /chat/file`
- `POST /chat/resume`
- `GET /internal/diagnostics/pending-actions`
- `GET /internal/diagnostics/metrics?format=json`
- `GET /internal/diagnostics/metrics?format=prometheus`

## Variaveis principais

- `OPENAI_API_KEY`
- `OPENAI_MODEL`
- `RUNTIME_SERVICE_TOKEN`
- `LARAVEL_BASE_URL`
- `LARAVEL_AGENT_SECRET`
- `PENDING_ACTIONS_BACKEND`
- `PENDING_ACTIONS_DATABASE_URL`
- `PENDING_ACTIONS_DB_PATH`
- `PENDING_ACTIONS_TTL_MINUTES`
- `PENDING_ACTIONS_MAX_REPEAT_COUNT`

## Execucao local

```bash
cd agent-runtime
python3 -m venv .venv
source .venv/bin/activate
pip install -e .
uvicorn app.main:app --reload --port 8787
```

## Contrato com o Laravel

Entrada de chat:

- `mensagem`
- `user_id`
- `user_name`
- `user_email`
- `session_id`
- `tipo_processamento`
- `arquivo` opcional

Saida esperada:

- `mensagem`
- `acao_sugerida`
- `dados_estruturados`

Confirmacao:

- o runtime devolve `dados_estruturados.metadata.runtime_pending_action_id`;
- o frontend ja envia `metadata` para `/api/chat/confirmar`;
- o `ChatController` pode encaminhar a aprovacao para `/chat/resume`.

## Observabilidade

Endpoints internos autenticados:

- `GET /internal/diagnostics/pending-actions`
- `GET /internal/diagnostics/metrics?format=json`
- `GET /internal/diagnostics/metrics?format=prometheus`

Artefatos incluídos:

- alertas Prometheus: [`observability/prometheus-alerts.yml`](/home/renan/workspace/medintelligence/clinica-backend/agent-runtime/observability/prometheus-alerts.yml)
- dashboard Grafana: [`observability/grafana-dashboard.json`](/home/renan/workspace/medintelligence/clinica-backend/agent-runtime/observability/grafana-dashboard.json)

## Smoke test pós-deploy

Script incluído:

- [`scripts/runtime_smoke_test.py`](/home/renan/workspace/medintelligence/clinica-backend/agent-runtime/scripts/runtime_smoke_test.py)

Exemplo:

```bash
python3 agent-runtime/scripts/runtime_smoke_test.py \
  --base-url http://127.0.0.1:8787 \
  --api-base-url http://127.0.0.1:8000 \
  --token "$CHATBOT_RUNTIME_SECRET" \
  --require-auth-checks
```
