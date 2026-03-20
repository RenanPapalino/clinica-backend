# Deploy de Produção

## Arquivos principais

- `Dockerfile`
- `agent-runtime/Dockerfile`
- `docker-compose.yml`
- `docker-compose.prod.yml`
- `.env.example`
- `agent-runtime/.env.example`

## URLs de produção

- API Laravel: `https://api.papalino.com.br`
- Frontend: `https://app.papalino.com.br`
- n8n chatbot: `https://n8n-n8n-start.aeuv6j.easypanel.host/webhook/medintelligence_chatbot_ia`
- n8n parser: `https://n8n-n8n-start.aeuv6j.easypanel.host/webhook/medintelligence_parser_fatura`

## Variáveis mínimas no `.env`

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.papalino.com.br
FRONTEND_URL=https://app.papalino.com.br

DB_CONNECTION=mysql
DB_HOST=SEU_HOST_MYSQL
DB_PORT=3306
DB_DATABASE=SEU_BANCO
DB_USERNAME=SEU_USUARIO
DB_PASSWORD=SEU_SEGREDO

CHATBOT_RUNTIME_DRIVER=langchain
CHATBOT_RUNTIME_URL=http://langchain-runtime:8787
CHATBOT_RUNTIME_SECRET=SEU_SEGREDO_RUNTIME

OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4.1-mini
OPENAI_VISION_MODEL=gpt-4.1-mini
OPENAI_TRANSCRIPTION_MODEL=whisper-1

N8N_INGEST_SECRET=SEU_SEGREDO_N8N
N8N_WEBHOOK_CHAT_URL=https://n8n-n8n-start.aeuv6j.easypanel.host/webhook/medintelligence_chatbot_ia
N8N_WEBHOOK_URL=https://n8n-n8n-start.aeuv6j.easypanel.host/webhook/medintelligence_parser_fatura

CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE=true
GOOGLE_DRIVE_FOLDER_ID=SEU_FOLDER_ID
GOOGLE_DRIVE_OAUTH_ACCESS_TOKEN=SEU_ACCESS_TOKEN
GOOGLE_DRIVE_OAUTH_CLIENT_ID=SEU_CLIENT_ID
GOOGLE_DRIVE_OAUTH_CLIENT_SECRET=SEU_CLIENT_SECRET
GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN=SEU_REFRESH_TOKEN
```

## Build e subida

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

## Comandos úteis

Subir migrations automaticamente:

```bash
RUN_MIGRATIONS=true docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Ver estado dos serviços:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
```

Validar healthchecks:

```bash
curl -fsS http://127.0.0.1:${API_PORT:-8000}/up
curl -fsS http://127.0.0.1:${RUNTIME_PORT:-8787}/health
```

## Observações

- O backend publica Apache na porta interna `80`.
- O runtime LangChain publica FastAPI na porta interna `8787`.
- O build do Laravel agora já inclui os assets do Vite.
- Não suba `.env` real para o git.
