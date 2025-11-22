<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ServicoController;
use App\Http\Controllers\Api\FaturaController;
use App\Http\Controllers\Api\NfseController;
use App\Http\Controllers\Api\TituloController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\N8nController;
use App\Http\Controllers\Api\DashboardController;


Route::get('/debug-auth', function () {
    echo "<h1>Diagnóstico de Autenticação</h1>";
    
    // 1. Teste de Conexão com Banco
    try {
        DB::connection()->getPdo();
        echo "<p style='color:green'>✅ 1. Banco de Dados Conectado.</p>";
    } catch (\Exception $e) {
        echo "<p style='color:red'>❌ 1. Erro de Conexão: " . $e->getMessage() . "</p>";
        die();
    }

    // 2. Teste de Usuário
    try {
        $user = User::first();
        if (!$user) {
            echo "<p style='color:orange'>⚠️ 2. Tabela 'users' acessível, mas vazia. Rode as seeds.</p>";
        } else {
            echo "<p style='color:green'>✅ 2. Usuário encontrado: " . $user->email . "</p>";
        }
    } catch (\Exception $e) {
        echo "<p style='color:red'>❌ 2. Erro ao acessar Model User (Tabela existe?): " . $e->getMessage() . "</p>";
        die();
    }

    // 3. Teste do Sanctum (O Ponto Crítico)
    if ($user) {
        if (!method_exists($user, 'createToken')) {
            echo "<p style='color:red'>❌ 3. Método 'createToken' NÃO existe no User.</p>";
            echo "<pre>Verifique se 'use HasApiTokens' está dentro da classe em app/Models/User.php</pre>";
        } else {
            echo "<p style='color:green'>✅ 3. Método 'createToken' detectado.</p>";
            
            // 4. Tentar Criar o Token (Teste Real)
            try {
                $token = $user->createToken('TesteDebug')->plainTextToken;
                echo "<p style='color:green'>✅ 4. Token gerado com sucesso: " . substr($token, 0, 10) . "...</p>";
                echo "<p><b>Se você vê isso, o login deveria estar funcionando. Limpe o cache!</b></p>";
            } catch (\Exception $e) {
                echo "<p style='color:red'>❌ 4. Erro FATAL ao criar token: " . $e->getMessage() . "</p>";
                echo "<p>Provavelmente falta a tabela <b>personal_access_tokens</b>.</p>";
            }
        }
    }
});

// ============================================
// HEALTH CHECK
// ============================================
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'Clinica Financeiro API',
        'version' => '1.0.0',
    ]);
});

// ============================================
// DB TEST
// ============================================
Route::get('/db-test', function () {
    try {
        \DB::connection()->getPdo();
        return response()->json(['database' => 'connected']);
    } catch (\Exception $e) {
        return response()->json([
            'database' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

// ============================================
// AUTENTICAÇÃO
// ============================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// ============================================
// ROTAS PROTEGIDAS
// ============================================
Route::middleware('auth:sanctum')->group(function () {

    // ============================================
    // DASHBOARD (NOVO)
    // ============================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/titulos-vencendo', [DashboardController::class, 'titulosVencendo']);
        Route::get('/acoes-pendentes', [DashboardController::class, 'acoesPendentes']);
    });

    // ============================================
    // CADASTROS
    // ============================================
    Route::prefix('cadastros')->group(function () {
        // Clientes
        Route::apiResource('clientes', ClienteController::class);

        // Importação em lote
        Route::post('clientes/importar', [ClienteController::class, 'importarLote']);

        // Serviços
        Route::apiResource('servicos', ServicoController::class);
    });

    // ============================================
    // FATURAMENTO
    // ============================================
    Route::prefix('faturamento')->group(function () {
        Route::get('faturas', [FaturaController::class, 'index']);
        Route::post('faturas', [FaturaController::class, 'store']);
        Route::get('faturas/{id}', [FaturaController::class, 'show']);
        Route::put('faturas/{id}', [FaturaController::class, 'update']);
        Route::delete('faturas/{id}', [FaturaController::class, 'destroy']);
        Route::post('faturas/{id}/itens', [FaturaController::class, 'adicionarItem']);

        Route::get('estatisticas', [FaturaController::class, 'estatisticas']);
    });

    // ============================================
    // NFSE
    // ============================================
    Route::prefix('nfse')->group(function () {
        Route::get('/', [NfseController::class, 'index']);
        Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
        Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
    });

    // ============================================
    // CONTAS A RECEBER
    // ============================================
    Route::prefix('contas-receber')->group(function () {
        Route::apiResource('titulos', TituloController::class);
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // ============================================
    // RELATÓRIOS
    // ============================================
    Route::prefix('relatorios')->group(function () {
        Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
        Route::get('/faturamento-periodo', [RelatorioController::class, 'faturamentoPorPeriodo']);
        Route::get('/top-clientes', [RelatorioController::class, 'topClientes']);
    });

// Chat / IA
Route::prefix('chat')->group(function () {
    Route::post('/mensagem', [ChatController::class, 'enviarMensagem']);
    Route::get('/historico', [ChatController::class, 'historico']);
});


Route::prefix('auth')->group(function () {
    // ... login, register
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
});

    // ============================================
    // N8N - INTEGRAÇÕES
    // ============================================
    Route::prefix('n8n')->group(function () {
        Route::post('/webhook', [N8nController::class, 'webhook']);
        Route::get('/buscar-cliente', [N8nController::class, 'buscarClientePorCnpj']);
        Route::get('/buscar-servico', [N8nController::class, 'buscarServicoPorCodigo']);
        Route::post('/processar-planilha-soc', [N8nController::class, 'processarPlanilhaSoc']);
        Route::get('/titulos-a-vencer', [N8nController::class, 'titulosAVencer']);
        Route::get('/titulos-vencidos', [N8nController::class, 'titulosVencidos']);
    });

    Route::prefix('faturamento')->group(function () {
    // ...
    Route::post('importar-lote', [FaturaController::class, 'importarLote']);
});


    // Financeiro Avançado
Route::apiResource('fornecedores', FornecedorController::class);
Route::apiResource('titulos', TituloController::class); // Substitui o antigo se houver
Route::get('planos-contas', [PlanoContaController::class, 'index']);
Route::get('centros-custo', [CentroCustoController::class, 'index']);

}); // fim das rotas com auth

Route::post('faturamento/analisar', [FaturaController::class, 'analisarArquivo']);
Route::post('faturamento/processar-confirmados', [FaturaController::class, 'processarLoteConfirmado']);
Route::post('cadastros/clientes/sincronizar-soc', [ClienteController::class, 'sincronizarSoc']);

Route::apiResource('lancamentos-contabeis', LancamentoContabilController::class)->only(['index', 'store', 'show']);
Route::post('contabilidade/processar-titulo/{id}', [LancamentoContabilController::class, 'processarTitulo']);