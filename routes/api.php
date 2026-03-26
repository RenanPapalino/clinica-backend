<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

// Controllers
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\ServicoController;
use App\Http\Controllers\Api\PlanoContaController;
use App\Http\Controllers\Api\CentroCustoController;
use App\Http\Controllers\Api\FaturaController;
use App\Http\Controllers\Api\NfseController;
use App\Http\Controllers\Api\TituloController;
use App\Http\Controllers\Api\CobrancaController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\Contabilidade\LancamentoContabilController as LivroRazaoController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\N8nController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DespesaController;
use App\Http\Controllers\Api\ConfiguracaoController;
use App\Http\Controllers\Api\OrdemServicoController;
use App\Http\Controllers\Api\FaturamentoController;
use App\Http\Controllers\Api\LancamentoContabilController as LancamentoContabilCrudController;
use App\Http\Controllers\Api\AgentToolController;
use App\Http\Controllers\Api\N8nRagController;


// ============================================
// ROTAS PÚBLICAS (Health & Debug)
// ============================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'MedIntelligence API',
        'version' => '2.2.0',
    ]);
});

if (app()->environment('local')) {
    Route::get('/db-test', function () {
        try {
            DB::connection()->getPdo();
            return response()->json(['database' => 'connected']);
        } catch (\Exception $e) {
            return response()->json(['database' => 'error', 'message' => $e->getMessage()], 500);
        }
    });
}

// ============================================
// AUTENTICAÇÃO (Pública)
// ============================================
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// ============================================
// ROTAS INTERNAS (RUNTIME AGENTE E INGESTAO RAG)
// ============================================
Route::prefix('internal/n8n/rag')->middleware('n8n.ingest')->group(function () {
    Route::post('/upsert', [N8nRagController::class, 'upsert']);
    Route::post('/delete', [N8nRagController::class, 'delete']);
});

Route::prefix('internal/agent')->middleware('agent.runtime')->group(function () {
    Route::post('/session-context', [AgentToolController::class, 'sessionContext']);
    Route::post('/knowledge/search', [AgentToolController::class, 'searchKnowledge']);
    Route::post('/cnpj/consultar', [AgentToolController::class, 'consultarCnpj']);
    Route::get('/financial-summary', [AgentToolController::class, 'financialSummary']);
    Route::post('/faturamento/summary', [AgentToolController::class, 'faturamentoSummary']);
    Route::post('/caixa/previsao', [AgentToolController::class, 'previsaoCaixa']);
    Route::post('/clientes/search', [AgentToolController::class, 'searchClientes']);
    Route::post('/servicos/search', [AgentToolController::class, 'searchServicos']);
    Route::post('/clientes/status', [AgentToolController::class, 'updateClienteStatus']);
    Route::post('/clientes/upsert', [AgentToolController::class, 'upsertCliente']);
    Route::post('/cobrancas/inadimplentes', [AgentToolController::class, 'searchCobrancasInadimplentes']);
    Route::post('/cobrancas/registrar', [AgentToolController::class, 'registrarCobrancaAutomacao']);
    Route::post('/fornecedores/search', [AgentToolController::class, 'searchFornecedores']);
    Route::post('/titulos/search', [AgentToolController::class, 'searchTitulos']);
    Route::post('/titulos/baixar', [AgentToolController::class, 'baixarTitulo']);
    Route::post('/titulos/renegociar', [AgentToolController::class, 'renegociarTitulo']);
    Route::post('/faturas/search', [AgentToolController::class, 'searchFaturas']);
    Route::post('/faturas/gerar-boleto', [AgentToolController::class, 'gerarBoleto']);
    Route::post('/faturas/excluir-boleto', [AgentToolController::class, 'excluirBoleto']);
    Route::post('/faturas/excluir', [AgentToolController::class, 'excluirFatura']);
    Route::post('/nfse/search', [AgentToolController::class, 'searchNfse']);
    Route::post('/nfse/emitir', [AgentToolController::class, 'emitirNfse']);
    Route::post('/fechamento/diario', [AgentToolController::class, 'fechamentoDiario']);
    Route::post('/despesas/search', [AgentToolController::class, 'searchDespesas']);
    Route::post('/despesas/baixar', [AgentToolController::class, 'baixarDespesa']);
    Route::post('/clientes', [AgentToolController::class, 'createCliente']);
    Route::post('/contas-receber', [AgentToolController::class, 'createContaReceber']);
    Route::post('/contas-pagar', [AgentToolController::class, 'createContaPagar']);
    Route::post('/faturas', [AgentToolController::class, 'createFatura']);
});

// ============================================
// ROTAS PROTEGIDAS (Requer Login)
// ============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth User Info
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // ========== DASHBOARD COMPLETO ==========
    Route::prefix('dashboard')->group(function () {
        Route::get('/kpis-completos', [DashboardController::class, 'kpisCompletos']);
        Route::get('/titulos-vencendo', [DashboardController::class, 'titulosVencendo']);
        Route::get('/acoes-pendentes', [DashboardController::class, 'acoesPendentes']);
        Route::get('/ultimas-faturas', [DashboardController::class, 'ultimasFaturas']);
        Route::get('/fluxo-caixa', [DashboardController::class, 'fluxoCaixa']);
        Route::get('/graficos', [DashboardController::class, 'graficos']);
        Route::get('/top-clientes', [DashboardController::class, 'topClientes']);
        Route::get('/receita-por-servico', [DashboardController::class, 'receitaPorServico']);
        Route::get('/taxa-inadimplencia', [DashboardController::class, 'taxaInadimplencia']);
    });

    // ========== ORDENS DE SERVIÇO ==========
    Route::prefix('ordens-servico')->group(function () {
        Route::get('/', [OrdemServicoController::class, 'index']);
        Route::get('/{id}', [OrdemServicoController::class, 'show']);
        Route::post('/', [OrdemServicoController::class, 'store']);
        Route::put('/{id}', [OrdemServicoController::class, 'update']);
        Route::delete('/{id}', [OrdemServicoController::class, 'destroy']);
        Route::post('/importar-soc', [OrdemServicoController::class, 'importarSoc']);
        Route::post('/{id}/faturar', [OrdemServicoController::class, 'faturar']);
    });

    // ========== CADASTROS GERAIS ==========
    Route::prefix('cadastros')->group(function () {
        Route::get('clientes/consultar-cpf', [ClienteController::class, 'consultarCpf']);
        Route::get('clientes/consultar-cnpj', [ClienteController::class, 'consultarCnpj']);
        Route::get('clientes/consultar-cep', [ClienteController::class, 'consultarCep']);
        Route::apiResource('clientes', ClienteController::class);
        Route::post('clientes/confirmar-importacao', [ClienteController::class, 'confirmarImportacao']);
        
        Route::apiResource('servicos', ServicoController::class);
        Route::apiResource('planos-contas', PlanoContaController::class);
        Route::apiResource('centros-custo', CentroCustoController::class);
    });

    // Fornecedores
    Route::get('fornecedores/consultar-cnpj', [FornecedorController::class, 'consultarCnpj']);
    Route::get('fornecedores/consultar-cep', [FornecedorController::class, 'consultarCep']);
    Route::apiResource('fornecedores', FornecedorController::class);

    // ========== FATURAMENTO INTELIGENTE ==========
    Route::prefix('faturamento')->group(function () {
        Route::get('faturas', [FaturaController::class, 'index']);
        Route::post('faturas', [FaturaController::class, 'store']);
        Route::post('faturas/processar-lote', [FaturaController::class, 'processarLote']);
        Route::delete('faturas/excluir-lote', [FaturaController::class, 'destroyLote']);
        Route::get('faturas/{id}', [FaturaController::class, 'show']);
        Route::put('faturas/{id}', [FaturaController::class, 'update']);
        Route::delete('faturas/{id}', [FaturaController::class, 'destroy']);
        
        Route::post('faturas/{id}/itens', [FaturaController::class, 'adicionarItem']);
        Route::get('estatisticas', [FaturaController::class, 'estatisticas']);
        
        Route::post('analisar', [FaturaController::class, 'analisarArquivo']);
        Route::post('processar-confirmados', [FaturaController::class, 'processarLoteConfirmado']);
        Route::post('importar-lote', [FaturaController::class, 'importarLote']);
        Route::post('importar-soc', [FaturaController::class, 'importarSoc']);
        Route::post('emitir-nfse/{id}', [FaturaController::class, 'emitirNfse']);
        Route::post('faturas/{id}/gerar-boleto', [FaturamentoController::class, 'gerarBoleto']);
    });

    // ========== NFS-e (Hub Fiscal) ==========
    Route::prefix('nfse')->group(function () {
        Route::get('/', [NfseController::class, 'index']);
        Route::get('/guias', [NfseController::class, 'guias']);
        Route::post('/', [NfseController::class, 'store']);
        Route::get('/{id}', [NfseController::class, 'show']);
        Route::put('/{id}', [NfseController::class, 'update']);
        Route::delete('/{id}', [NfseController::class, 'destroy']);
        Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
        Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
        Route::get('/{id}/xml', [NfseController::class, 'downloadXml']);
        Route::get('/{id}/pdf', [NfseController::class, 'downloadPdf']);
        Route::post('/{id}/cancelar', [NfseController::class, 'cancelar']);
    });

    // ========== TÍTULOS / CONTAS A RECEBER ==========
    Route::apiResource('titulos', TituloController::class);
    
    Route::prefix('contas-receber')->group(function () {
        Route::get('titulos', [TituloController::class, 'index']);
        Route::post('titulos', [TituloController::class, 'store']);
        Route::get('titulos/{id}', [TituloController::class, 'show']);
        Route::put('titulos/{id}', [TituloController::class, 'update']);
        Route::delete('titulos/{id}', [TituloController::class, 'destroy']);
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::post('titulos/{id}/registrar-boleto', [TituloController::class, 'registrarBoleto']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // ========== CONTAS A PAGAR (Despesas) ==========
    Route::prefix('contas-pagar')->group(function () {
        Route::get('despesas', [DespesaController::class, 'index']);
        Route::post('despesas', [DespesaController::class, 'store']);
        Route::post('despesas/analisar-documento', [DespesaController::class, 'analisarDocumento']);
        Route::post('despesas/analisar-codigo-barras', [DespesaController::class, 'analisarCodigoBarras']);
        Route::post('despesas/{id}/pagar', [DespesaController::class, 'pagar']);
    });

    // ========== CONTABILIDADE ==========
    Route::prefix('contabilidade')->group(function () {
        Route::get('lancamentos', [LivroRazaoController::class, 'index']);
        Route::get('balancete', [LivroRazaoController::class, 'balancete']);

        Route::post('livro-razao/auditar-ia', [LivroRazaoController::class, 'auditarIa']);
        Route::post('livro-razao/{id}/aprovar-ia', [LivroRazaoController::class, 'aprovarIa']);
        Route::post('livro-razao/{id}/revisar-ia', [LivroRazaoController::class, 'revisarIa']);

        Route::get('livro-razao/export-ofx', [LivroRazaoController::class, 'exportOfx']);
        Route::get('livro-razao/export-excel', [LivroRazaoController::class, 'exportExcel']);
    });

    Route::apiResource('lancamentos-contabeis', LancamentoContabilCrudController::class)->only(['index', 'store', 'show']);

    // ========== COBRANÇAS ==========
    Route::prefix('cobrancas')->group(function () {
        Route::get('/', [CobrancaController::class, 'index']);
        Route::get('inadimplentes', [CobrancaController::class, 'inadimplentes']);
        Route::get('relatorio', [CobrancaController::class, 'relatorio']);
        Route::post('enviar-whatsapp/{clienteId}', [CobrancaController::class, 'enviarWhatsApp']);
        Route::post('enviar-email/{clienteId}', [CobrancaController::class, 'enviarEmail']);
        Route::post('enviar-lote', [CobrancaController::class, 'enviarLote']);
        Route::post('gerar-remessa', [CobrancaController::class, 'gerarRemessa']);
        Route::post('processar-retorno', [CobrancaController::class, 'processarRetorno']);
    });

    // ========== RELATÓRIOS ==========
    Route::prefix('relatorios')->group(function () {
        Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
        Route::get('/faturamento-periodo', [RelatorioController::class, 'faturamentoPorPeriodo']);
        Route::get('/top-clientes', [RelatorioController::class, 'topClientes']);
        Route::get('/fluxo-caixa-real', [RelatorioController::class, 'fluxoCaixaReal']);
        Route::get('/dre-real', [RelatorioController::class, 'dreReal']);
        Route::get('/exportar-pdf', [RelatorioController::class, 'exportarPdf']);
    });

    // ========== CONFIGURAÇÕES ==========
    Route::prefix('configuracoes')->group(function () {
        Route::get('/empresa', [ConfiguracaoController::class, 'getEmpresa']);
        Route::put('/empresa', [ConfiguracaoController::class, 'updateEmpresa']);
        Route::post('/upload-logo', [ConfiguracaoController::class, 'uploadLogo']);
        Route::get('/usuarios', [ConfiguracaoController::class, 'getUsuarios']);
        Route::post('/usuarios', [ConfiguracaoController::class, 'storeUsuario']);
        Route::put('/usuarios/{id}', [ConfiguracaoController::class, 'updateUsuario']);
        Route::post('/usuarios/{id}/reenviar-acesso', [ConfiguracaoController::class, 'reenviarAcessoUsuario']);
        Route::delete('/usuarios/{id}', [ConfiguracaoController::class, 'destroyUsuario']);
        Route::get('/integracoes', [ConfiguracaoController::class, 'getIntegracoes']);
        Route::put('/integracoes', [ConfiguracaoController::class, 'updateIntegracoes']);
        Route::get('/fiscal', [ConfiguracaoController::class, 'getFiscal']);
        Route::put('/fiscal', [ConfiguracaoController::class, 'updateFiscal']);
    });

    // ============================================
    // CHAT IA - ROTAS ATUALIZADAS
    // ============================================
    Route::prefix('chat')->group(function () {
        /**
         * Enviar mensagem (com ou sem arquivo)
         * 
         * POST /api/chat/enviar
         * 
         * Body (FormData para upload):
         * - mensagem: string (opcional se tiver arquivo)
         * - arquivo: file (opcional)
         * - tipo_processamento: 'auto'|'clientes'|'servicos'|'financeiro'
         * - session_id: string (opcional)
         */
        Route::post('/enviar', [ChatController::class, 'enviarMensagem']);
        
        /**
         * Buscar histórico de mensagens
         * GET /api/chat/historico
         */
        Route::get('/historico', [ChatController::class, 'historico']);
        
        /**
         * Limpar histórico de mensagens
         * DELETE /api/chat/limpar
         */
        Route::delete('/limpar', [ChatController::class, 'limparHistorico']);
        
        /**
         * Confirmar ação sugerida pela IA (importar, criar fatura, etc)
         * 
         * POST /api/chat/confirmar
         * 
         * Body:
         * - acao: 'importar_clientes'|'criar_fatura'|'cadastrar_servicos'
         * - dados: array de registros a processar
         */
        Route::post('/confirmar', [ChatController::class, 'confirmarAcao']);
    });

    // ========== N8N INTEGRATIONS ==========
    Route::prefix('n8n')->group(function () {
        Route::post('/webhook', [N8nController::class, 'webhook']);
        Route::get('/buscar-cliente', [N8nController::class, 'buscarClientePorCnpj']);
        Route::get('/buscar-servico', [N8nController::class, 'buscarServicoPorCodigo']);
        Route::post('/processar-planilha-soc', [N8nController::class, 'processarPlanilhaSoc']);
        Route::get('/titulos-a-vencer', [N8nController::class, 'titulosAVencer']);
        Route::get('/titulos-vencidos', [N8nController::class, 'titulosVencidos']);
    });

}); // Fim do middleware auth:sanctum
