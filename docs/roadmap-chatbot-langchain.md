# Roadmap de Evolucao para Chatbot e LangChain

Gerado em 2026-03-19 com base no estado atual do backend Laravel.

Status atual:

- Fase 0 parcialmente iniciada;
- rotas publicas de manutencao ja foram removidas;
- parte das rotas quebradas ja foi corrigida ou retirada da superficie publica;
- a Fase 1 ja tem bootstrap de testes, factories centrais e cobertura inicial dos fluxos de `Fatura` e `Titulo`;
- `User` e `Cobranca` ja foram alinhados com o schema real;
- a Fase 2 foi iniciada com a extracao de `CriarFaturaManualAction` e `BaixarTituloAction`;
- a Fase 3 ja recebeu a primeira padronizacao de integracao para o runtime de IA e para ingestao RAG via n8n;
- a base do runtime LangChain sobre MySQL ja possui tools internas autenticadas para clientes, titulos, despesas, contexto de sessao e conhecimento documental;
- o servico Python `agent-runtime/` ja existe com endpoints `/chat`, `/chat/file` e `/chat/resume`;
- `php artisan test` volta a executar com sucesso, mas ainda ha warnings deprecados em PHP 8.5 vindos de `vendor/laravel/framework` e Collision;
- ainda restam inconsistencias de schema e contratos internos para fechar o endurecimento da base.

## Objetivo

Preparar o MedIntelligence para um chatbot operacional capaz de consultar dados, sugerir acoes e executar rotinas com seguranca, auditabilidade e baixo acoplamento.

## Principio de execucao

Nao iniciar pela camada de agente.

A ordem recomendada e:

1. endurecer a base;
2. estabilizar contratos;
3. extrair funcoes de negocio;
4. criar gateway LLM;
5. integrar LangChain;
6. ampliar automacoes.

## Fase 0 - Hardening da Base

Objetivo: eliminar riscos de seguranca e quebradeiras obvias.

Entregas:

- remover ou proteger rotas publicas de manutencao em `routes/api.php`;
- alinhar rotas com metodos realmente implementados;
- corrigir divergencias graves entre migrations, models e controllers;
- revisar `.env.example` e documentar todas as variaveis de integracao;
- corrigir divergencia de porta no Docker.

Criterio de aceite:

- nenhum endpoint administrativo publico sem autenticacao;
- `php artisan route:list` sem rotas apontando para metodos faltantes;
- ambientes sobem com configuracao previsivel.

## Fase 1 - Contratos e Testes

Objetivo: criar uma base segura para refactor continuo.

Entregas:

- decidir se a suite sera Pest ou PHPUnit puro;
- instalar/configurar a ferramenta escolhida;
- criar factories para `Cliente`, `Servico`, `Fatura`, `Titulo`, `Fornecedor`, `Despesa`;
- cobrir fluxos criticos:
  - login;
  - criacao de OS;
  - faturamento de OS;
  - criacao de fatura manual;
  - baixa de titulo;
  - importacao confirmada via chat;
- adicionar testes de contrato para respostas JSON.

Criterio de aceite:

- `php artisan test` verde;
- cobertura minima para os fluxos centrais do negocio.

Atualizacao da iteracao de 2026-03-19:

- a suite foi convertida para PHPUnit puro no estado atual do repositorio;
- foram adicionadas factories base para `Cliente`, `Fatura` e `Titulo`;
- os fluxos de criacao manual de fatura, geracao automatica de titulo, criacao direta de titulo e baixa de titulo ja estao cobertos;
- o criterio funcional de execucao da suite foi atingido, mas ainda falta limpar as deprecacoes do stack de dependencias em PHP 8.5 para considerar a fase totalmente estabilizada.

## Fase 2 - Consolidacao da Camada de Aplicacao

Objetivo: tirar regra de negocio dos controllers.

Entregas:

- criar uma camada `app/Actions` ou `app/Application`;
- extrair acoes com entradas e saidas claras:
  - `ImportarClientesAction`
  - `GerarOrdemServicoAction`
  - `GerarFaturaDeOsAction`
  - `CriarFaturaManualAction`
  - `BaixarTituloAction`
  - `RegistrarBoletoAction`
  - `EmitirCobrancaAction`
  - `AnalisarDocumentoDespesaAction`
- deixar controllers finos, apenas validacao HTTP e serializacao.

Criterio de aceite:

- controllers sem logica longa;
- acoes testaveis de forma isolada;
- mesma acao reutilizada por API, chat e jobs.

Atualizacao da iteracao de 2026-03-19:

- `CriarFaturaManualAction` ja foi extraida de `FaturaController`;
- `BaixarTituloAction` ja foi extraida de `TituloController` e reutilizada em `CobrancaController` para retorno bancario;
- `CriarClienteAction`, `CriarTituloAction` e `CriarDespesaAction` agora sustentam tanto controllers HTTP quanto tools internas do agente;
- a camada `app/Actions/Rag` foi aberta para ingestao, exclusao e busca documental em MySQL;
- a camada `app/Actions/Financeiro` foi aberta como ponto de consolidacao da aplicacao.

## Fase 3 - Padronizacao de Integracoes

Objetivo: transformar integracoes externas em adaptadores consistentes.

Entregas:

- padronizar clientes de integracao para:
  - N8N
  - SOC
  - OpenAI
  - Itau
  - NFSe prefeitura
- centralizar retries, timeout, logs, correlation id e erros;
- mover segredos sensiveis para configuracao consistente;
- padronizar DTOs/payloads de entrada e saida.

Criterio de aceite:

- nenhuma integracao externa chamada diretamente de controller;
- erros de integracao mapeados e auditaveis.

Atualizacao da iteracao de 2026-03-19:

- middleware dedicado foi criado para autenticar `agent.runtime` e `n8n.ingest`;
- `ChatController` passou a usar `AiChatGatewayService`;
- o backend ja expoe rotas internas para tools e para ingestao RAG;
- o `ChatController` agora tambem pode delegar confirmacoes de acoes criadas pelo LangChain para o endpoint `/chat/resume` do runtime;
- ainda falta extrair os adaptadores externos restantes de cobranca, SOC e NFSe para considerar a fase concluida.

## Fase 4 - Gateway LLM

Objetivo: introduzir IA de forma controlada antes do agente.

Entregas:

- criar um `LLMGateway` unico para chamadas de modelo;
- separar casos de uso:
  - extracao estruturada;
  - classificacao;
  - resumo;
  - resposta conversacional;
- definir schema JSON para todas as respostas estruturadas;
- registrar prompts versionados;
- criar logs de auditoria por chamada.

Criterio de aceite:

- IA responde sempre em contratos validaveis;
- falhas de parse nao quebram o fluxo de negocio.

## Fase 5 - LangChain

Objetivo: plugar um agente sobre tools de negocio estaveis.

Entregas:

- criar um processo/servico dedicado ao agente, preferencialmente em Python;
- expor tools baseadas nas actions da Fase 2;
- implementar memoria curta por sessao;
- manter human-in-the-loop para acoes destrutivas ou financeiras;
- adicionar controle de permissao por perfil de usuario.

Tools iniciais recomendadas:

- `buscar_cliente`
- `listar_titulos`
- `listar_kpis_financeiros`
- `gerar_ordem_servico`
- `gerar_fatura`
- `baixar_titulo`
- `emitir_cobranca`
- `consultar_nfse`
- `analisar_documento_despesa`

Criterio de aceite:

- agente apenas consulta ou executa actions autorizadas;
- toda acao relevante fica auditada;
- acoes de impacto exigem confirmacao explicita.

Atualizacao da iteracao de 2026-03-19:

- a base de tools do agente ja existe no Laravel para:
  - contexto de sessao;
  - busca documental;
  - resumo financeiro;
  - busca de clientes;
  - busca de titulos;
  - busca de despesas;
  - criacao de cliente;
  - criacao de conta a receber;
  - criacao de conta a pagar.
- o servico Python do runtime LangChain ja foi scaffoldado em `agent-runtime/`, consumindo essas tools e devolvendo preview estruturado para confirmacao.
- o proximo passo da fase e colocar esse runtime para rodar no ambiente de homologacao e ampliar o catalogo de tools.

## Fase 6 - Evolucao Funcional do Assistente

Objetivo: transformar o chatbot em copiloto operacional.

Entregas sugeridas:

- cobranca inteligente por perfil de atraso;
- classificacao contabil assistida;
- conciliacao de recebimentos;
- priorizacao de pendencias do dia;
- copiloto de faturamento mensal;
- monitor de excecoes de NFSe e boletos;
- sugestao de proximas acoes para o financeiro.

## Ordem recomendada para as proximas sessoes

### Passo 1

Corrigir seguranca e higiene do backend:

- remover rotas publicas de manutencao;
- alinhar rotas quebradas;
- limpar documentacao antiga.

### Passo 2

Corrigir contratos de dados:

- `Titulo` com `descricao`;
- `User` com `role` e `ativo` ou ajustar controller;
- `Cobranca` para o schema real;
- `DespesaController` e dependencias ausentes;
- `Fatura` e campos tributarios.

Status atual:

- `Titulo`, `User`, `Cobranca`, `DespesaController` e `Fatura` ja tiveram as divergencias mais criticas tratadas;
- permanecem pendencias menores e warnings de dependencias, mas o nucleo financeiro ja esta consistente para seguir com extracao de actions.

### Passo 3

Reativar a suite de testes.

Status atual:

- concluido no nivel funcional;
- pendente apenas o saneamento dos warnings deprecados do ambiente.

### Passo 4

Extrair actions do fluxo de negocio e, depois, do fluxo de chat.

Status atual:

- `CriarFaturaManualAction` e `BaixarTituloAction` ja foram extraidas;
- o proximo lote recomendado e `EmitirCobrancaAction`, `GerarFaturaDeOsAction` e `RegistrarBoletoAction`.

### Passo 5

Trocar heuristicas locais do chat por tools reais sobre a camada de aplicacao.

### Passo 6

Implementar o primeiro agente com LangChain sobre um subconjunto seguro:

- consulta de clientes;
- consulta de titulos;
- consulta de dashboard;
- importacao assistida de clientes;
- geracao assistida de OS.

## O que nao fazer agora

- nao conectar LangChain direto aos controllers atuais;
- nao permitir que o agente escreva em banco sem camadas de validacao;
- nao misturar prompts com regras fiscais/financeiras criticas;
- nao depender de retorno livre do modelo para acionar persistencia.

## Meta de arquitetura alvo

Arquitetura sugerida apos a estabilizacao:

```text
Frontend / Chat UI
    -> API Laravel
        -> Controllers finos
            -> Actions / Application Services
                -> Domain Models
                -> Integration Adapters
                -> Tool Registry
                    -> Servico Python com LangChain/LangGraph
```

## Resultado esperado

Ao final desse roadmap, o chatbot deixa de ser um webhook procedural e passa a ser uma camada de orquestracao confiavel sobre funcoes de negocio explicitamente modeladas.
