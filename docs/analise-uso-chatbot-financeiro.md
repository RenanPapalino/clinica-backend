# Analise de Uso do Chatbot Financeiro para Clinicas SST

## Perfil de uso principal

O usuario financeiro de uma clinica SST normalmente alterna entre 5 frentes ao longo do dia:

- acompanhar contas a receber, cobrancas e inadimplencia
- conferir contas a pagar e vencimentos da semana
- preparar faturamento mensal e validar faturas antes da emissao
- responder duvidas operacionais sobre clientes, fornecedores, boletos, titulos e regras internas
- executar cadastros ou lancamentos sem navegar por muitas telas

Nesse contexto, o chatbot funciona melhor quando combina 3 comportamentos:

- consulta rapida de dados vivos do sistema
- preparacao assistida de acoes com confirmacao humana
- orientacao operacional com proximo passo claro

## Casos de uso prioritarios

### 1. Contas a receber

Perguntas naturais:

- quais titulos vencidos eu tenho hoje
- quanto tenho para receber ate sexta
- me mostre os 10 maiores titulos em aberto
- existe titulo em atraso da clinica alfa

Evolucao recomendada:

- detalhar valor, vencimento, cliente e status na resposta
- sugerir a proxima acao: cobrar, renegociar ou abrir a ficha do cliente
- permitir `baixar_titulo` e `renegociar_titulo` com confirmacao

### 2. Contas a pagar

Perguntas naturais:

- o que vence esta semana
- quais boletos do fornecedor X estao pendentes
- quanto eu tenho a pagar hoje e amanha
- ha despesa sem fornecedor vinculado

Evolucao recomendada:

- responder com soma total e proximos vencimentos
- destacar registros sem classificacao contabil ou centro de custo
- permitir `baixar_despesa` com confirmacao e evoluir depois para `agendar_pagamento`

### 3. Faturamento

Perguntas naturais:

- quais faturas ainda nao foram emitidas neste mes
- gere a fatura da clinica beta para marco
- me mostre as ultimas faturas da clinica gama
- quais faturas estao sem NFS-e

Evolucao recomendada:

- consolidar itens por cliente e periodo
- mostrar total previsto antes da confirmacao
- permitir `emitir_nfse` com confirmacao e evoluir depois para `reenviar_boleto`

### 4. Cadastro operacional

Perguntas naturais:

- cadastre este novo cliente
- crie uma conta a pagar para o laboratorio apoio
- lance uma conta a receber para a clinica delta com vencimento no dia 15

Evolucao recomendada:

- perguntar apenas o que falta
- reutilizar contexto da conversa anterior
- sugerir resolucao automatica de cliente/fornecedor por nome ou documento

### 5. Apoio documental e treinamento

Perguntas naturais:

- qual e a regra de cobranca de boleto atrasado
- como funciona nosso fluxo de faturamento
- o manual fala algo sobre cancelamento de NFS-e

Evolucao recomendada:

- citar o arquivo consultado
- diferenciar claramente dado vivo do sistema e regra documental
- oferecer resumo curto com opcao de aprofundar

### 6. Fiscal e NFS-e

Perguntas naturais:

- quais NFS-e estao pendentes hoje
- emita a NFS-e da fatura FAT-202603-0012
- existe NFS-e com erro para a clinica alfa
- me mostre o protocolo da ultima NFS-e emitida

Evolucao recomendada:

- localizar a fatura correta mesmo quando o usuario informar cliente e competencia em vez do numero
- explicar claramente quando a NFS-e foi apenas registrada localmente
- sugerir o proximo passo operacional: consultar protocolo, corrigir erro ou reenviar

### 7. Fechamento diario

Perguntas naturais:

- como fechou o financeiro hoje
- qual o saldo realizado do dia
- quantos titulos vencidos seguem em aberto
- tenho NFS-e pendentes ou com erro hoje

Evolucao recomendada:

- resumir previstos, realizados, vencidos e pendencias no mesmo bloco
- destacar divergencias entre realizado e previsto
- abrir com o numero principal e fechar com a maior pendencia do dia

## Melhorias de UX conversacional

### Como a IA deve responder

- abrir com a resposta ou conclusao, nao com explicacao longa
- incluir total financeiro e principais datas quando houver dados
- fechar com uma proxima acao util, por exemplo `se quiser, eu preparo a confirmacao`
- quando nao encontrar dados, dizer isso explicitamente e sugerir filtros alternativos

### Como a IA deve perguntar

- pedir no maximo 3 campos por vez
- usar linguagem de negocio, nao nomes tecnicos internos
- aproveitar historico e anexo antes de perguntar de novo
- em acoes financeiras, confirmar sempre com resumo antes de gravar

Exemplos melhores:

- em vez de `faltam campos obrigatorios: cliente, data_vencimento`
- usar `Consigo preparar a conta a receber, mas ainda preciso do cliente e da data de vencimento.`

- em vez de `preparei 1 registro para confirmacao`
- usar `Preparei 1 fatura somando R$ 3.450,00 para vencimento em 31/03/2026. Posso confirmar a criacao?`

## Funcoes novas recomendadas

Prioridade alta:

- baixar titulo de contas a receber
- baixar despesa ou conta a pagar
- buscar faturas por cliente, status, periodo e NFS-e
- buscar NFS-e por numero, protocolo, status e cliente
- listar pendencias de faturamento do mes
- listar titulos vencidos com total consolidado

Prioridade media:

- emitir NFS-e de fatura pronta
- gerar ou reenviar boleto
- renegociar vencimento de titulo
- cadastrar fornecedor
- classificar despesa sem plano de contas

Prioridade estrategica:

- cobranca assistida com sugestao de mensagem por WhatsApp
- fechamento financeiro diario com resumo automatico
- previsao de caixa para 7, 15 e 30 dias
- deteccao de anomalias em inadimplencia, atraso ou queda de faturamento
- copiloto de auditoria para conferencia de OS, fatura, titulo e NFS-e

## Proximo backlog recomendado

1. implementar `emitir_nfse` com integracao fiscal real alem do registro local
2. evoluir `fechamento_diario` com comparativo previsto x realizado
3. adicionar `reenviar_boleto` e `gerar_boleto`
4. adicionar `cadastrar_fornecedor`
5. criar automacoes n8n de cobranca e fechamento programado
