# Roteiro de Testes do Chatbot em Producao

Este roteiro cobre a validacao do fluxo RAG via n8n e do runtime LangChain via Laravel.

## Escopo

- Ingestao documental do Google Drive para MySQL RAG
- Consulta de conhecimento documental no chatbot
- Consulta operacional no banco da aplicacao
- Criacao confirmada de cliente
- Criacao confirmada de conta a pagar
- Criacao confirmada de conta a receber

## Arquivos e endpoints

- Workflow n8n de producao: `docs/n8n-medintelligence-rag-ingest-mysql-producao.json`
- Upsert RAG: `POST /api/internal/n8n/rag/upsert`
- Delete RAG: `POST /api/internal/n8n/rag/delete`
- Chat: `POST /api/chat/enviar`
- Confirmacao de acao: `POST /api/chat/confirmar`
- Login API: `POST /api/auth/login`
- Runtime health: `GET /health`

## Pre-flight

1. Rodar migrations no backend.

```bash
php artisan migrate
```

2. Garantir estas variaveis no Laravel.

```env
CHATBOT_RUNTIME_DRIVER=langchain
CHATBOT_RUNTIME_URL=http://SEU_RUNTIME:8787
CHATBOT_RUNTIME_SECRET=defina-um-secret-forte
N8N_INGEST_SECRET=defina-um-secret-forte
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4.1-mini
```

3. Garantir estas variaveis no runtime Python.

```env
APP_PORT=8787
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4.1-mini
RUNTIME_SERVICE_TOKEN=mesmo-valor-de-CHATBOT_RUNTIME_SECRET
LARAVEL_BASE_URL=http://SEU_LARAVEL
LARAVEL_AGENT_SECRET=mesmo-valor-de-CHATBOT_RUNTIME_SECRET
REQUEST_TIMEOUT_SECONDS=20
MAX_RESULT_ROWS=10
DEFAULT_HISTORY_LIMIT=20
```

4. Garantir estas variaveis no n8n.

```env
LARAVEL_BASE_URL=http://SEU_LARAVEL
N8N_INGEST_SECRET=mesmo-valor-do-Laravel
RAG_BUSINESS_CONTEXT=geral
RAG_CONTEXT_KEY=drive
```

5. Validar servicos no ar.

```bash
curl -s "$CHATBOT_RUNTIME_URL/health"
php artisan route:list --path=internal/n8n/rag
php artisan route:list --path=chat
```

## Importacao do workflow no n8n

1. Importar [n8n-medintelligence-rag-ingest-mysql-producao.json](/Users/papalino/workspace/medintelligence/clinica-backend/docs/n8n-medintelligence-rag-ingest-mysql-producao.json)
2. Confirmar que os 4 nos do Google Drive estao usando a credencial `N8N`
3. Confirmar que a pasta monitorada e `1gdNPlghZaOsXMRjJ39Ssp_AFgiIgb-_a`
4. Ativar o workflow

## Teste 1: Novo arquivo no RAG

1. Enviar um PDF ou XLSX novo para a pasta monitorada.
2. Confirmar no n8n uma execucao bem-sucedida com resposta `200` do Laravel.
3. Validar no banco que o documento foi criado.

```bash
php artisan tinker --execute="dump(App\\Models\\RagDocument::query()->latest('id')->first(['id','source_system','external_id','file_name','business_context','context_key','current_version','chunks_count','status'])->toArray());"
```

Resultado esperado:

- `status = active`
- `current_version = 1`
- `chunks_count >= 1`
- `source_system = google_drive`

## Teste 2: Update do mesmo arquivo

1. Alterar o mesmo arquivo no Google Drive.
2. Aguardar nova execucao do workflow.
3. Validar que a nova versao foi indexada sem perder o historico anterior.

```bash
php artisan tinker --execute="dump(App\\Models\\RagDocument::query()->where('external_id','SEU_FILE_ID')->first(['id','external_id','current_version','chunks_count','status'])->toArray());"
php artisan tinker --execute="dump(App\\Models\\RagChunk::query()->whereHas('document', fn ($q) => $q->where('external_id','SEU_FILE_ID'))->orderBy('version')->get(['version','chunk_index','is_active'])->toArray());"
```

Resultado esperado:

- `current_version` incrementado
- chunks da versao anterior com `is_active = false`
- chunks da nova versao com `is_active = true`

## Teste 3: Delete do arquivo

1. Excluir o arquivo do Google Drive.
2. Aguardar a execucao do evento `fileDeleted`.
3. Validar que o documento foi removido do indice logico.

```bash
php artisan tinker --execute="dump(optional(App\\Models\\RagDocument::withTrashed()->where('external_id','SEU_FILE_ID')->first(['id','external_id','deleted_at','status']))?->toArray());"
php artisan tinker --execute="dump(App\\Models\\RagChunk::query()->whereHas('document', fn ($q) => $q->withTrashed()->where('external_id','SEU_FILE_ID'))->where('is_active', true)->count());"
```

Resultado esperado:

- `deleted_at` preenchido
- nenhum chunk ativo restante

## Preparacao para testes do chat

1. Obter token Sanctum.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/auth/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "SEU_USUARIO",
    "password": "SUA_SENHA"
  }'
```

2. Guardar o `token` retornado.

3. Definir uma sessao de teste.

```bash
export CHAT_SESSION_ID="sessao-chatbot-producao-001"
export API_TOKEN="cole-o-token-aqui"
```

## Teste 4: Pergunta operacional no banco

Enviar uma pergunta que dependa de dados vivos da aplicacao.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/enviar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mensagem": "Quais titulos em aberto e vencidos eu tenho hoje?",
    "tipo_processamento": "financeiro",
    "session_id": "'"$CHAT_SESSION_ID"'"
  }'
```

Resultado esperado:

- resposta `success = true`
- resposta baseada em dados reais do MySQL
- nenhum `acao_sugerida` de escrita se a pergunta for apenas consulta

## Teste 5: Pergunta sobre documento indexado

Enviar uma pergunta ancorada em um documento que acabou de ser indexado.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/enviar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mensagem": "Segundo o manual financeiro indexado, qual e o procedimento para cobranca de inadimplentes?",
    "tipo_processamento": "financeiro",
    "session_id": "'"$CHAT_SESSION_ID"'"
  }'
```

Resultado esperado:

- resposta `success = true`
- resposta coerente com o documento enviado ao RAG
- nenhuma tentativa de criacao de registro

## Teste 6: Criacao de cliente com confirmacao

1. Pedir a criacao do cliente.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/enviar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mensagem": "Cadastre o cliente Clinica Alfa SST LTDA, CNPJ 12.345.678/0001-90, email financeiro@clinicaalfa.com e telefone 11999990000.",
    "tipo_processamento": "clientes",
    "session_id": "'"$CHAT_SESSION_ID"'"
  }' > /tmp/chat-cliente.json
```

2. Validar que a resposta veio em modo de confirmacao.

Resultado esperado:

- `acao_sugerida = criar_cliente`
- `dados_estruturados.metadata.runtime_pending_action_id` preenchido
- nenhum cliente criado antes da confirmacao

3. Confirmar a acao com o mesmo `dados_estruturados.dados_mapeados` e `dados_estruturados.metadata` da resposta anterior.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/confirmar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "acao": "criar_cliente",
    "dados": [
      {
        "cnpj": "12345678000190",
        "razao_social": "CLINICA ALFA SST LTDA",
        "nome_fantasia": "CLINICA ALFA SST",
        "email": "financeiro@clinicaalfa.com",
        "telefone": "11999990000"
      }
    ],
    "metadata": {
      "fonte": "langchain-runtime",
      "runtime_pending_action_id": "SUBSTITUA_PELO_ID_DA_RESPOSTA"
    },
    "session_id": "'"$CHAT_SESSION_ID"'"
  }'
```

4. Validar no banco.

```bash
php artisan tinker --execute="dump(App\\Models\\Cliente::query()->where('cnpj','12345678000190')->latest('id')->first(['id','cnpj','razao_social','email','telefone','status'])->toArray());"
```

## Teste 7: Criacao de conta a pagar com confirmacao

1. Pedir a criacao da despesa.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/enviar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mensagem": "Crie uma conta a pagar de 320,25 para o fornecedor LAB APOIO SST LTDA, vencimento em 2026-03-30, descricao Pagamento laboratorio parceiro.",
    "tipo_processamento": "financeiro",
    "session_id": "'"$CHAT_SESSION_ID"'"
  }' > /tmp/chat-pagar.json
```

2. Confirmar a acao usando o `runtime_pending_action_id` retornado.

Use o mesmo `dados_estruturados.dados_mapeados` retornado pelo chat. No exemplo abaixo, substitua `fornecedor_id` pelo valor real resolvido pelo runtime.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/confirmar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "acao": "criar_conta_pagar",
    "dados": [
      {
        "descricao": "Pagamento laboratorio parceiro",
        "valor": 320.25,
        "data_vencimento": "2026-03-30",
        "fornecedor_id": 1
      }
    ],
    "metadata": {
      "fonte": "langchain-runtime",
      "runtime_pending_action_id": "SUBSTITUA_PELO_ID_DA_RESPOSTA"
    },
    "session_id": "'"$CHAT_SESSION_ID"'"
  }'
```

Resultado esperado:

- `acao_sugerida = criar_conta_pagar`
- nenhum registro criado antes da confirmacao
- apos confirmar, uma `despesa` com `status = pendente`

Validacao no banco:

```bash
php artisan tinker --execute="dump(App\\Models\\Despesa::query()->latest('id')->first(['id','descricao','valor','data_vencimento','status','fornecedor_id'])->toArray());"
```

## Teste 8: Criacao de conta a receber com confirmacao

1. Pedir a criacao do titulo.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/enviar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mensagem": "Crie uma conta a receber para o cliente Clinica Alfa SST LTDA no valor de 750,50 com vencimento em 2026-04-10 e descricao Mensalidade de abril.",
    "tipo_processamento": "financeiro",
    "session_id": "'"$CHAT_SESSION_ID"'"
  }' > /tmp/chat-receber.json
```

2. Confirmar a acao usando o `runtime_pending_action_id` retornado.

Use o mesmo `dados_estruturados.dados_mapeados` retornado pelo chat. No exemplo abaixo, substitua `cliente_id` pelo valor real resolvido pelo runtime.

```bash
curl -s -X POST "$LARAVEL_BASE_URL/api/chat/confirmar" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "acao": "criar_conta_receber",
    "dados": [
      {
        "cliente_id": 1,
        "descricao": "Mensalidade de abril",
        "data_vencimento": "2026-04-10",
        "valor_original": 750.50
      }
    ],
    "metadata": {
      "fonte": "langchain-runtime",
      "runtime_pending_action_id": "SUBSTITUA_PELO_ID_DA_RESPOSTA"
    },
    "session_id": "'"$CHAT_SESSION_ID"'"
  }'
```

Resultado esperado:

- `acao_sugerida = criar_conta_receber`
- nenhum titulo criado antes da confirmacao
- apos confirmar, um `titulo` com `tipo = receber` e `status = aberto`

Validacao no banco:

```bash
php artisan tinker --execute="dump(App\\Models\\Titulo::query()->latest('id')->first(['id','descricao','tipo','status','valor_original','data_vencimento','cliente_id'])->toArray());"
```

## Criterios de aceite

- O workflow do n8n cria, atualiza e remove documentos RAG sem depender de PGVector
- O chatbot responde perguntas operacionais usando o MySQL da aplicacao
- O chatbot responde perguntas documentais usando o indice RAG do MySQL
- Acoes de escrita so acontecem depois de confirmacao explicita
- Cliente, conta a pagar e conta a receber sao persistidos com sucesso apos confirmacao

## Falhas que exigem ajuste antes do rollout

- `401` nos endpoints internos do Laravel
- `502` no `/api/chat/confirmar`
- runtime sem responder em `/health`
- workflow do n8n executando sem chegar ao Laravel
- documento RAG ficando com `chunks_count = 0`
- acao de escrita acontecendo antes da confirmacao
