<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
use App\Http\Controllers\Api\LancamentoContabilController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\N8nController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DespesaController;
use App\Http\Controllers\Api\ConfiguracaoController;

// ============================================
// ROTAS PÚBLICAS (Health & Debug)
// ============================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'MedIntelligence API',
        'version' => '2.0.0',
    ]);
});

Route::get('/db-test', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['database' => 'connected']);
    } catch (\Exception $e) {
        return response()->json(['database' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// ============================================
// AUTENTICAÇÃO (Pública)
// ============================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
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
        Route::get('/graficos', [DashboardController::class, 'graficos']); // Consolidado
        Route::get('/top-clientes', [DashboardController::class, 'topClientes']);
        Route::get('/receita-por-servico', [DashboardController::class, 'receitaPorServico']);
        Route::get('/taxa-inadimplencia', [DashboardController::class, 'taxaInadimplencia']);
    });

    // Cadastros Gerais (Hub)
    Route::prefix('cadastros')->group(function () {
        Route::apiResource('clientes', ClienteController::class);
        Route::post('clientes/importar', [ClienteController::class, 'importarLote']);
        Route::post('clientes/sincronizar-soc', [ClienteController::class, 'sincronizarSoc']);
        
        Route::apiResource('servicos', ServicoController::class);
    });

    // Financeiro Avançado (Tabelas Auxiliares)
    Route::apiResource('fornecedores', FornecedorController::class);
    Route::get('planos-contas', [PlanoContaController::class, 'index']);
    Route::get('centros-custo', [CentroCustoController::class, 'index']);

    // Faturamento Inteligente
    Route::prefix('faturamento')->group(function () {
        Route::get('faturas', [FaturaController::class, 'index']);
        Route::post('faturas', [FaturaController::class, 'store']);
        Route::get('faturas/{id}', [FaturaController::class, 'show']);
        Route::put('faturas/{id}', [FaturaController::class, 'update']);
        Route::delete('faturas/{id}', [FaturaController::class, 'destroy']);
        
        Route::post('faturas/{id}/itens', [FaturaController::class, 'adicionarItem']);
        Route::get('estatisticas', [FaturaController::class, 'estatisticas']);
        
        // Novas rotas inteligentes
        Route::post('analisar', [FaturaController::class, 'analisarArquivo']);
        Route::post('processar-confirmados', [FaturaController::class, 'processarLoteConfirmado']);
        Route::post('importar-lote', [FaturaController::class, 'importarLote']);
    });

    // NFS-e (Hub Fiscal)
    Route::prefix('nfse')->group(function () {
        Route::get('/', [NfseController::class, 'index']);
        Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
        Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
        
        // Novos endpoints
        Route::get('/{id}/xml', [NfseController::class, 'downloadXml']);
        Route::get('/{id}/pdf', [NfseController::class, 'downloadPdf']);
        Route::post('/{id}/cancelar', [NfseController::class, 'cancelar']);
    });

    // Contas a Receber & Pagar (Títulos Unificados)
    Route::apiResource('titulos', TituloController::class);
    
    Route::prefix('contas-receber')->group(function () {
        Route::get('titulos', [TituloController::class, 'index']); 
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // Contas a Pagar (Despesas)
    Route::prefix('contas-pagar')->group(function () {
        Route::get('despesas', [DespesaController::class, 'index']);
        Route::post('despesas', [DespesaController::class, 'store']);
        Route::post('despesas/analisar-documento', [DespesaController::class, 'analisarDocumento']);
        Route::post('despesas/{id}/pagar', [DespesaController::class, 'pagar']);
    });

    // ========== COBRANÇAS (NOVO MÓDULO COMPLETO) ==========
    Route::prefix('cobrancas')->group(function () {
        Route::get('inadimplentes', [CobrancaController::class, 'inadimplentes']);
        Route::post('enviar-whatsapp/{clienteId}', [CobrancaController::class, 'enviarWhatsApp']);
        Route::post('enviar-email/{clienteId}', [CobrancaController::class, 'enviarEmail']);
        Route::post('enviar-lote', [CobrancaController::class, 'enviarLote']);
        Route::post('gerar-remessa', [CobrancaController::class, 'gerarRemessa']);
        Route::post('processar-retorno', [CobrancaController::class, 'processarRetorno']);
    });
    
    // Contabilidade Inteligente
    Route::apiResource('lancamentos-contabeis', LancamentoContabilController::class)->only(['index', 'store', 'show']);
    Route::post('contabilidade/processar-titulo/{id}', [LancamentoContabilController::class, 'processarTitulo']);
    Route::get('contabilidade/balancete', [LancamentoContabilController::class, 'balancete']);
    Route::get('contabilidade/dre-real', [LancamentoContabilController::class, 'dreReal']);

    // Relatórios
    Route::prefix('relatorios')->group(function () {
        Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
        Route::get('/faturamento-periodo', [RelatorioController::class, 'faturamentoPorPeriodo']);
        Route::get('/top-clientes', [RelatorioController::class, 'topClientes']);
        
        // Novos endpoints para dados reais
        Route::get('/fluxo-caixa-real', [RelatorioController::class, 'getFluxoCaixaReal']);
        Route::get('/dre-real', [RelatorioController::class, 'getDREReal']);
        Route::post('/exportar-pdf', [RelatorioController::class, 'exportarPDF']);
    });

    // ========== CONFIGURAÇÕES (NOVO MÓDULO COMPLETO) ==========
    Route::prefix('configuracoes')->group(function () {
        // Empresa
        Route::get('/empresa', [ConfiguracaoController::class, 'getEmpresa']);
        Route::put('/empresa', [ConfiguracaoController::class, 'updateEmpresa']);
        Route::post('/upload-logo', [ConfiguracaoController::class, 'uploadLogo']);
        
        // Usuários
        Route::get('/usuarios', [ConfiguracaoController::class, 'getUsuarios']);
        Route::post('/usuarios', [ConfiguracaoController::class, 'storeUsuario']);
        Route::put('/usuarios/{id}', [ConfiguracaoController::class, 'updateUsuario']);
        Route::delete('/usuarios/{id}', [ConfiguracaoController::class, 'destroyUsuario']);
        
        // Integrações
        Route::get('/integracoes', [ConfiguracaoController::class, 'getIntegracoes']);
        Route::put('/integracoes', [ConfiguracaoController::class, 'updateIntegracoes']);
        
        // Fiscal
        Route::get('/fiscal', [ConfiguracaoController::class, 'getFiscal']);
        Route::put('/fiscal', [ConfiguracaoController::class, 'updateFiscal']);
    });

    // Chat IA
    Route::prefix('chat')->group(function () {
        Route::post('/mensagem', [ChatController::class, 'enviarMensagem']);
        Route::get('/historico', [ChatController::class, 'historico']);
    });

    // N8N Integrations (Internas)
    Route::prefix('n8n')->group(function () {
        Route::post('/webhook', [N8nController::class, 'webhook']);
        Route::get('/buscar-cliente', [N8nController::class, 'buscarClientePorCnpj']);
        Route::get('/buscar-servico', [N8nController::class, 'buscarServicoPorCodigo']);
        Route::post('/processar-planilha-soc', [N8nController::class, 'processarPlanilhaSoc']);
        Route::get('/titulos-a-vencer', [N8nController::class, 'titulosAVencer']);
        Route::get('/titulos-vencidos', [N8nController::class, 'titulosVencidos']);
    });

}); // Fim do middleware auth:sanctum

// ============================================
// ROTAS DE DEBUG (REMOVER EM PRODUÇÃO)
// ============================================

Route::get('/debug-senha', function () {
    $email = 'papalino@papalino.com.br';
    $senha = 'papalino';
    
    $user = \App\Models\User::where('email', $email)->first();
    
    if (!$user) {
        return response()->json(['erro' => 'Usuário não encontrado']);
    }

    $check = \Illuminate\Support\Facades\Hash::check($senha, $user->password);
    
    return response()->json([
        'status' => 'Diagnóstico',
        'email_banco' => $user->email,
        'senha_testada' => $senha,
        'hash_no_banco' => $user->password,
        'resultado_check' => $check ? '✅ SENHA CORRETA' : '❌ SENHA INCORRETA',
        'algoritmo' => \Illuminate\Support\Facades\Hash::info($user->password)
    ]);
});

Route::get('/criar-admin-force', function () {
    $email = 'papalino@papalino.com.br';
    $senha = 'papalino';

    $userAntigo = User::where('email', $email)->first();
    if ($userAntigo) {
        $userAntigo->delete();
    }

    try {
        $user = User::create([
            'name' => 'Papalino Admin',
            'email' => $email,
            'password' => Hash::make($senha),
            'role' => 'admin',
            'ativo' => true
        ]);
        
        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Usuário criado com sucesso!',
            'email' => $user->email,
            'senha_para_usar' => $senha
        ]);
    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage()], 500);
    }
});
