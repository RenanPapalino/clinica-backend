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

## Variaveis principais

- `OPENAI_API_KEY`
- `OPENAI_MODEL`
- `RUNTIME_SERVICE_TOKEN`
- `LARAVEL_BASE_URL`
- `LARAVEL_AGENT_SECRET`

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
