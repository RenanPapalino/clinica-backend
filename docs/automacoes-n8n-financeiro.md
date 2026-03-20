# Automacoes N8N do Financeiro

Este pacote adiciona tres automacoes reais no n8n, usando o Laravel como fonte operacional e o runtime LangChain como camada de redacao e resumo:

- cobranca assistida agendada;
- fechamento diario agendado;
- alertas de NFS-e pendente e com erro.

Arquivo para importacao:

- `docs/n8n-medintelligence-automacoes-financeiras.json`

## O que cada automacao faz

### 1. Cobranca assistida

Fluxo:

1. cron dispara em dias uteis as 08:00;
2. n8n chama `POST /api/internal/agent/cobrancas/inadimplentes`;
3. Laravel devolve clientes inadimplentes, titulos vencidos, prioridade e canal sugerido;
4. n8n chama o runtime LangChain para redigir a mensagem de cobranca;
5. n8n envia a mensagem para o webhook de saida configurado;
6. n8n registra o evento em `cobrancas` via `POST /api/internal/agent/cobrancas/registrar`.

### 2. Fechamento diario

Fluxo:

1. cron dispara em dias uteis as 18:30;
2. n8n chama `POST /api/internal/agent/fechamento/diario`;
3. o runtime LangChain resume o fechamento em linguagem executiva;
4. n8n envia o resumo para o webhook do financeiro.

### 3. Alertas de NFS-e

Fluxo:

1. cron dispara de hora em hora em dias uteis;
2. n8n consulta `POST /api/internal/agent/nfse/search` com status `pendente`;
3. n8n consulta `POST /api/internal/agent/nfse/search` com status `erro`;
4. o runtime LangChain consolida o alerta fiscal;
5. n8n envia a notificacao para o webhook fiscal.

## Campos que voce precisa ajustar no workflow

Troque estes placeholders antes de ativar:

- `COLE_CHATBOT_RUNTIME_SECRET_AQUI`
- `https://SEU_RUNTIME_URL_AQUI/chat`
- `https://SEU_WEBHOOK_COBRANCA_ASSISTIDA_AQUI`
- `https://SEU_WEBHOOK_ALERTA_FINANCEIRO_AQUI`
- `https://SEU_WEBHOOK_ALERTA_FISCAL_AQUI`
- `X-Agent-User-Id = 1`

Recomendacao:

- use um usuario ativo do sistema so para automacao;
- se possivel, crie um usuario tecnico para o n8n;
- troque o `X-Agent-User-Id` no workflow para esse usuario.

## Contratos usados pelo workflow

Laravel interno:

- `POST /api/internal/agent/cobrancas/inadimplentes`
- `POST /api/internal/agent/cobrancas/registrar`
- `POST /api/internal/agent/fechamento/diario`
- `POST /api/internal/agent/nfse/search`

Runtime LangChain:

- `POST /chat`

## Como testar no n8n

### Cobranca assistida

1. importe o workflow;
2. abra `Manual Cobranca Assistida`;
3. execute o fluxo;
4. confirme se:
   - `Buscar Inadimplentes` trouxe clientes;
   - `Gerar Mensagem IA Cobranca` devolveu `mensagem`;
   - `Enviar Cobranca Assistida` chamou o webhook externo;
   - `Registrar Cobranca no Laravel` retornou `201`.

### Fechamento diario

1. execute `Manual Fechamento Diario`;
2. confirme se:
   - `Buscar Fechamento Diario` retornou `success = true`;
   - `Gerar Resumo IA Fechamento` devolveu resumo textual;
   - `Notificar Fechamento Financeiro` recebeu o payload.

### Alertas de NFS-e

1. execute `Manual Alerta NFSe`;
2. confirme se:
   - `Buscar NFSe Pendentes` e `Buscar NFSe Com Erro` retornaram dados;
   - `Consolidar Alertas NFSe` filtrou corretamente quando nao houver itens;
   - `Gerar Resumo IA NFSe` devolveu resumo textual;
   - `Notificar Alertas NFSe` recebeu o payload final.

## Observacoes

- o workflow foi gerado com `active: false`;
- os webhooks de saida foram deixados genericos de proposito, porque o canal final pode ser WhatsApp API, e-mail, Slack, outro workflow n8n ou qualquer endpoint interno;
- a cobranca assistida nao altera titulos automaticamente; ela orquestra a comunicacao e registra o evento;
- a baixa do titulo continua sob confirmacao humana no chatbot ou em fluxo financeiro proprio.
