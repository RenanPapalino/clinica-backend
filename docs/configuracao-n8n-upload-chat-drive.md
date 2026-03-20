# Configuracao do n8n para Upload do Chat via Google Drive

Este guia configura o fluxo:

`usuario -> Laravel chat -> Google Drive -> n8n -> Laravel RAG`

## Arquivo a importar

Importe:

- [n8n-medintelligence-rag-ingest-mysql-producao-sem-env.json](/Users/papalino/workspace/medintelligence/clinica-backend/docs/n8n-medintelligence-rag-ingest-mysql-producao-sem-env.json)

## URLs de teste do workflow completo

Para o workflow unificado do chatbot com IA, parser de fatura e ingestao RAG, as URLs de teste da sua instancia n8n sao:

- `https://n8n-n8n-start.aeuv6j.easypanel.host/webhook-test/medintelligence_chatbot_ia`
- `https://n8n-n8n-start.aeuv6j.easypanel.host/webhook-test/medintelligence_parser_fatura`

Observacao:

- no JSON do workflow o n8n salva apenas o `path` do webhook
- como os paths ja sao `medintelligence_chatbot_ia` e `medintelligence_parser_fatura`, nao foi necessario alterar a estrutura do node `Webhook`
- ao importar esse workflow nessa instancia, essas passam a ser as URLs efetivas de teste

## Antes de abrir o n8n

No Laravel:

```env
CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE=true
CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE_REQUIRED=false
GOOGLE_DRIVE_OAUTH_CLIENT_ID=seu-client-id.apps.googleusercontent.com
GOOGLE_DRIVE_OAUTH_CLIENT_SECRET=seu-client-secret
GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN=seu-refresh-token
GOOGLE_DRIVE_FOLDER_ID=1gdNPlghZaOsXMRjJ39Ssp_AFgiIgb-_a
N8N_INGEST_SECRET=SEU_SECRET
```

Ponto critico:

- o backend vai subir o arquivo em nome da sua conta Google via OAuth 2.0
- a mesma pasta tambem precisa ser acessivel pela credencial Google `N8N` dentro do n8n

Observacao:

- `service account` continua suportada no codigo, mas para `My Drive` pessoal o caminho recomendado e `OAuth refresh token`
- `service account` so faz sentido se voce tiver `Shared Drive`

## Passo 1: credencial Google no n8n

Nos 4 nos abaixo, confirme a credencial `N8N`:

- `Arquivo Novo`
- `Arquivo Atualizado`
- `Arquivo Excluido`
- `Download File`

## Passo 2: pasta monitorada

Nos 3 triggers do Google Drive, confirme:

- `Trigger On`: pasta especifica
- `Folder`: `1gdNPlghZaOsXMRjJ39Ssp_AFgiIgb-_a`
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
    "folder_id": "1gdNPlghZaOsXMRjJ39Ssp_AFgiIgb-_a"
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
- token OAuth do Laravel aponta para outra conta Google

## Como gerar as credenciais OAuth no Google

### 1. Ativar a Google Drive API

- abra `https://console.cloud.google.com/apis/library/drive.googleapis.com`
- selecione o projeto usado pelo chatbot
- clique em `Ativar`

### 2. Criar um client OAuth 2.0

- va em `APIs e servicos` -> `Credenciais`
- clique em `Criar credenciais` -> `ID do cliente OAuth`
- se pedir, configure a tela de consentimento
- escolha `Aplicativo da Web`
- em `Authorized redirect URIs`, adicione:
  - `https://developers.google.com/oauthplayground`
- salve o `client_id` e o `client_secret`

### 3. Gerar o refresh token

- abra `https://developers.google.com/oauthplayground/`
- clique na engrenagem
- marque `Use your own OAuth credentials`
- cole o `client_id` e `client_secret`
- no passo 1, informe o escopo:
  - `https://www.googleapis.com/auth/drive`
- clique em `Authorize APIs`
- faca login na conta dona da pasta `RAG`
- clique em `Exchange authorization code for tokens`
- copie o `refresh_token`

Observacao:

- se a tela de consentimento ficar em `Testing`, o refresh token pode expirar em 7 dias
- para token de longa duracao, publique a app em `In production`

### 4. Preencher o Laravel

No `.env`:

```env
GOOGLE_DRIVE_OAUTH_CLIENT_ID=seu-client-id.apps.googleusercontent.com
GOOGLE_DRIVE_OAUTH_CLIENT_SECRET=seu-client-secret
GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN=seu-refresh-token
GOOGLE_DRIVE_FOLDER_ID=1gdNPlghZaOsXMRjJ39Ssp_AFgiIgb-_a
```

Depois rode:

```bash
php artisan config:clear
```

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
