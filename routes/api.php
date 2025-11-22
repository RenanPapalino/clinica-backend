<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\User;

// Controllers (Garanta que todos existem na pasta app/Http/Controllers/Api)
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\FornecedorController; // Novo
use App\Http\Controllers\Api\ServicoController;
use App\Http\Controllers\Api\PlanoContaController; // Novo
use App\Http\Controllers\Api\CentroCustoController; // Novo
use App\Http\Controllers\Api\FaturaController;
use App\Http\Controllers\Api\NfseController;
use App\Http\Controllers\Api\TituloController;
use App\Http\Controllers\Api\CobrancaController; // Faltava no seu use
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\LancamentoContabilController; // Novo
use App\Http\Controllers\Api\ChatController; // Novo
use App\Http\Controllers\Api\N8nController;
use App\Http\Controllers\Api\DashboardController;

/*
|--------------------------------------------------------------------------
| API Routes - MedIntelligence
|--------------------------------------------------------------------------
*/

// ============================================
// ROTAS PÚBLICAS (Health & Debug)
// ============================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'Clinica Financeiro API',
        'version' => '1.0.0',
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

// Rota de Debug de Login (Pode remover em produção)
Route::get('/debug-login', function () {
    // ... (código de debug que passamos antes)
    return response()->json(['status' => 'debug_route_active']);
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

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/titulos-vencendo', [DashboardController::class, 'titulosVencendo']);
        Route::get('/acoes-pendentes', [DashboardController::class, 'acoesPendentes']);
    });

    // Cadastros Gerais (Hub)
    Route::prefix('cadastros')->group(function () {
        Route::apiResource('clientes', ClienteController::class);
        Route::post('clientes/importar', [ClienteController::class, 'importarLote']);
        Route::post('clientes/sincronizar-soc', [ClienteController::class, 'sincronizarSoc']); // Movido para cá (Seguro)
        
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
        Route::post('analisar', [FaturaController::class, 'analisarArquivo']); // Movido para cá
        Route::post('processar-confirmados', [FaturaController::class, 'processarLoteConfirmado']); // Movido para cá
        Route::post('importar-lote', [FaturaController::class, 'importarLote']);
    });

    // NFS-e (Hub Fiscal)
    Route::prefix('nfse')->group(function () {
        Route::get('/', [NfseController::class, 'index']);
        Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
        Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
    });

    // Contas a Receber & Pagar (Títulos Unificados)
    Route::apiResource('titulos', TituloController::class); // CRUD Completo
    
    Route::prefix('contas-receber')->group(function () {
        // Atalhos para front-end legado
        Route::get('titulos', [TituloController::class, 'index']); 
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // Cobrança
    Route::prefix('cobrancas')->group(function () {
         // Se tiver métodos específicos além do CRUD de títulos
         Route::post('enviar/{faturaId}', [CobrancaController::class, 'enviarCobranca']); 
         Route::post('gerar-remessa', [CobrancaController::class, 'gerarRemessa']);
    });
    
    // Contabilidade Inteligente (Movido para área segura)
    Route::apiResource('lancamentos-contabeis', LancamentoContabilController::class)->only(['index', 'store', 'show']);
    Route::post('contabilidade/processar-titulo/{id}', [LancamentoContabilController::class, 'processarTitulo']);

    // Relatórios
    Route::prefix('relatorios')->group(function () {
        Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
        Route::get('/faturamento-periodo', [RelatorioController::class, 'faturamentoPorPeriodo']);
        Route::get('/top-clientes', [RelatorioController::class, 'topClientes']);
        // Endpoints para o Dashboard CFO
        Route::get('/fluxo-caixa', [RelatorioController::class, 'getFluxoCaixa']); // Assumindo que criou
        Route::get('/dre', [RelatorioController::class, 'getDRE']); // Assumindo que criou
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

Route::get('/debug-senha', function () {
    $email = 'papalino@papalino.com.br';
    $senha = 'papalino';
    
    // 1. Busca o usuário
    $user = \App\Models\User::where('email', $email)->first();
    
    if (!$user) {
        return response()->json(['erro' => 'Usuário não encontrado no banco com este e-mail exato.']);
    }

    // 2. Testa a senha
    $check = \Illuminate\Support\Facades\Hash::check($senha, $user->password);
    
    return response()->json([
        'status' => 'Diagnóstico',
        'email_banco' => $user->email,
        'senha_testada' => $senha,
        'hash_no_banco' => $user->password,
        'resultado_check' => $check ? '✅ SENHA CORRETA (O problema é o Front-end)' : '❌ SENHA INCORRETA (O problema é o Hash no Banco)',
        'algoritmo' => \Illuminate\Support\Facades\Hash::info($user->password)
    ]);
});