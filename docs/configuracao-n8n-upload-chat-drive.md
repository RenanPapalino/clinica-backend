# Configuracao do n8n para Upload do Chat via Google Drive

Este guia configura o fluxo:

`usuario -> Laravel chat -> Google Drive -> n8n -> Laravel RAG`

## Arquivo a importar

Importe:

- [n8n-medintelligence-rag-ingest-mysql-producao-sem-env.json](/Users/papalino/workspace/medintelligence/clinica-backend/docs/n8n-medintelligence-rag-ingest-mysql-producao-sem-env.json)

## Antes de abrir o n8n

No Laravel:

```env
CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE=true
CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE_REQUIRED=false
GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=/caminho/credentials.json
GOOGLE_DRIVE_FOLDER_ID=1GJiQDVdOJ8ljF4CS9kH6n7vKLrDzD_oi
N8N_INGEST_SECRET=SEU_SECRET
```

Ponto critico:

- a pasta `1GJiQDVdOJ8ljF4CS9kH6n7vKLrDzD_oi` precisa estar compartilhada com o e-mail da service account usada pelo Laravel
- a mesma pasta tambem precisa ser acessivel pela credencial Google `N8N` dentro do n8n

## Passo 1: credencial Google no n8n

Nos 4 nos abaixo, confirme a credencial `N8N`:

- `Arquivo Novo`
- `Arquivo Atualizado`
- `Arquivo Excluido`
- `Download File`

## Passo 2: pasta monitorada

Nos 3 triggers do Google Drive, confirme:

- `Trigger On`: pasta especifica
- `Folder`: `1GJiQDVdOJ8ljF4CS9kH6n7vKLrDzD_oi`
- `Watch For`:
  - `Arquivo Novo` -> `File Created`
  - `Arquivo Atualizado` -> `File Updated`
  - `Arquivo Excluido` -> `File Deleted`

Observacao:

- subpastas nao disparam esse fluxo

## Passo 3: nos HTTP

Edite estes nos:

- `Laravel Upsert XLSX`
- `Laravel Upsert PDF`
- `Laravel Upsert Texto`
- `Laravel Delete`

Troque:

- `COLE_O_SECRET_AQUI` pelo valor real de `N8N_INGEST_SECRET`

Exemplo:

- `https://api.papalino.com.br/api/internal/n8n/rag/upsert`
- header `X-N8N-Secret: seu-secret`

## Passo 4: o que cada no faz

### `Arquivo Novo`

Responsavel por detectar arquivo novo no Drive.

### `Normalizar Documento`

Responsavel por transformar a saida do trigger em um payload padrao para o restante do workflow.

Campos importantes:

- `business_context`: tenta usar `appProperties.tipo_processamento` do upload do chat
- `context_key`: tenta usar `appProperties.session_id`; se nao existir, cai para `folder_id`
- `chat_user_id`: tenta usar `appProperties.user_id`
- `upload_source`: tenta usar `appProperties.source`

### `Download File`

Baixa o binario real do arquivo para o n8n processar.

### `Tipo Arquivo`

Separa o caminho conforme o tipo:

- XLSX
- PDF
- texto

### `Extrair XLSX`, `Extrair PDF`, `Extrair Texto`

Transformam o arquivo em texto/chunks consumiveis pelo Laravel.

### `Laravel Upsert ...`

Enviam para o backend:

- metadados do arquivo
- contexto de negocio
- contexto de sessao quando existir
- conteudo extraido

## Passo 5: ativar

Depois de salvar tudo:

1. clique em `Fetch Test Event` em `Arquivo Novo`
2. se passar, ative o workflow

## Passo 6: primeiro teste real

1. envie um arquivo pelo chat
2. confirme na resposta do Laravel se `arquivo_ingestao.success = true`
3. confirme se o arquivo apareceu na pasta do Drive
4. confirme se o n8n executou o workflow
5. confirme no Laravel se o endpoint `upsert` respondeu `200`
6. confirme no banco se surgiram registros em `rag_documents` e `rag_chunks`

## O que esperar no retorno do chat

O retorno imediato do chat agora pode trazer:

```json
{
  "arquivo_ingestao": {
    "success": true,
    "provider": "google_drive",
    "file_id": "drive-file-001",
    "folder_id": "1GJiQDVdOJ8ljF4CS9kH6n7vKLrDzD_oi"
  }
}
```

Isso significa:

- o Laravel conseguiu subir o arquivo para o Drive
- o n8n ja pode pegar esse arquivo pelo trigger

## Falhas mais comuns

### `access to env vars denied`

Voce importou um workflow com `$env`.

Solucao:

- use o arquivo `...producao-sem-env.json`

### trigger nao encontra arquivo

Causas comuns:

- pasta errada
- credencial Google errada
- arquivo foi enviado para subpasta
- service account do Laravel subiu em uma pasta diferente da monitorada

### HTTP 401 ou 403 no Laravel

Causas comuns:

- `X-N8N-Secret` incorreto
- URL apontando para ambiente errado

### arquivo chega no Drive mas nao vai para o RAG

Causas comuns:

- workflow desativado
- erro no `Download File`
- erro no extrator do tipo de arquivo
- erro no `Laravel Upsert ...`
