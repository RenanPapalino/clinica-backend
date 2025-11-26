<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

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
use App\Http\Controllers\Api\OrdemServicoController;

// ============================================
// ROTAS PÚBLICAS (Health & Debug)
// ============================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'MedIntelligence API',
        'version' => '2.1.0',
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
        Route::get('/graficos', [DashboardController::class, 'graficos']);
        Route::get('/top-clientes', [DashboardController::class, 'topClientes']);
        Route::get('/receita-por-servico', [DashboardController::class, 'receitaPorServico']);
        Route::get('/taxa-inadimplencia', [DashboardController::class, 'taxaInadimplencia']);
    });

    Route::prefix('ordens-servico')->group(function () {
    Route::get('/', [OrdemServicoController::class, 'index']);
    Route::get('/{id}', [OrdemServicoController::class, 'show']);
    Route::post('/', [OrdemServicoController::class, 'store']); // <--- NOVA ROTA
    Route::post('/importar-soc', [OrdemServicoController::class, 'importarSoc']);
    Route::post('/{id}/faturar', [OrdemServicoController::class, 'faturar']);
});

    // Cadastros Gerais (Hub)
    Route::prefix('cadastros')->group(function () {
        Route::apiResource('clientes', ClienteController::class);
        Route::post('clientes/importar', [ClienteController::class, 'importarLote']);
        Route::post('clientes/sincronizar-soc', [ClienteController::class, 'sincronizarSoc']);
        
        Route::apiResource('servicos', ServicoController::class);
        
        // Rota de Importação Inteligente
        Route::post('clientes/confirmar-importacao', [ClienteController::class, 'confirmarImportacao']);
     

        // --- AQUI ESTAVAM FALTANDO AS ROTAS CORRETAS ---
        Route::apiResource('planos-contas', PlanoContaController::class);
        Route::apiResource('centros-custo', CentroCustoController::class);
    });

    // Financeiro Avançado (Tabelas Auxiliares)
    Route::apiResource('fornecedores', FornecedorController::class);
    
    // Rotas de leitura legadas (Mantidas para compatibilidade se necessário, mas fora do padrão REST completo)
    // Route::get('planos-contas', [PlanoContaController::class, 'index']); 
    // Route::get('centros-custo', [CentroCustoController::class, 'index']);

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
        Route::post('importar-soc', [FaturaController::class, 'importarSoc']);
        Route::post('emitir-nfse/{id}', [FaturaController::class, 'emitirNfse']);
    });

    // NFS-e (Hub Fiscal)
    Route::prefix('nfse')->group(function () {
        Route::get('/', [NfseController::class, 'index']);
        Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
        Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
        Route::get('/{id}/xml', [NfseController::class, 'downloadXml']);
        Route::get('/{id}/pdf', [NfseController::class, 'downloadPdf']);
        Route::post('/{id}/cancelar', [NfseController::class, 'cancelar']);
    });

    // Contas a Receber & Pagar (Títulos Unificados)
    Route::apiResource('titulos', TituloController::class);
    
    Route::prefix('contas-receber')->group(function () {
        Route::get('titulos', [TituloController::class, 'index']); 
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::post('titulos/{id}/registrar-boleto', [TituloController::class, 'registrarBoleto']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // Contas a Pagar (Despesas)
    Route::prefix('contas-pagar')->group(function () {
        Route::get('despesas', [DespesaController::class, 'index']);
        Route::post('despesas', [DespesaController::class, 'store']);
        Route::post('despesas/analisar-documento', [DespesaController::class, 'analisarDocumento']);
        Route::post('despesas/{id}/pagar', [DespesaController::class, 'pagar']);
    });

    // ========== COBRANÇAS ==========
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
        Route::get('/fluxo-caixa-real', [RelatorioController::class, 'getFluxoCaixaReal']);
        Route::get('/dre-real', [RelatorioController::class, 'getDREReal']);
        Route::post('/exportar-pdf', [RelatorioController::class, 'exportarPDF']);
    });

    // ========== CONFIGURAÇÕES ==========
    Route::prefix('configuracoes')->group(function () {
        Route::get('/empresa', [ConfiguracaoController::class, 'getEmpresa']);
        Route::put('/empresa', [ConfiguracaoController::class, 'updateEmpresa']);
        Route::post('/upload-logo', [ConfiguracaoController::class, 'uploadLogo']);
        Route::get('/usuarios', [ConfiguracaoController::class, 'getUsuarios']);
        Route::post('/usuarios', [ConfiguracaoController::class, 'storeUsuario']);
        Route::put('/usuarios/{id}', [ConfiguracaoController::class, 'updateUsuario']);
        Route::delete('/usuarios/{id}', [ConfiguracaoController::class, 'destroyUsuario']);
        Route::get('/integracoes', [ConfiguracaoController::class, 'getIntegracoes']);
        Route::put('/integracoes', [ConfiguracaoController::class, 'updateIntegracoes']);
        Route::get('/fiscal', [ConfiguracaoController::class, 'getFiscal']);
        Route::put('/fiscal', [ConfiguracaoController::class, 'updateFiscal']);
    });

    // Chat IA
    Route::prefix('chat')->group(function () {
        Route::post('/mensagem', [ChatController::class, 'enviarMensagem']);
        Route::get('/historico', [ChatController::class, 'historico']);
    });

    // N8N Integrations
    Route::prefix('n8n')->group(function () {
        Route::post('/webhook', [N8nController::class, 'webhook']);
        Route::get('/buscar-cliente', [N8nController::class, 'buscarClientePorCnpj']);
        Route::get('/buscar-servico', [N8nController::class, 'buscarServicoPorCodigo']);
        Route::post('/processar-planilha-soc', [N8nController::class, 'processarPlanilhaSoc']);
        Route::get('/titulos-a-vencer', [N8nController::class, 'titulosAVencer']);
        Route::get('/titulos-vencidos', [N8nController::class, 'titulosVencidos']);
    });

});

// Rota de Correção de Emergência
Route::get('/fix-database-manual', function () {
    $log = [];
    try {
        if (!Schema::hasTable('centros_custo')) {
            $log[] = '❌ ERRO CRÍTICO: Tabela centros_custo não existe!';
        } else {
            if (Schema::hasColumn('centros_custo', 'nome') && !Schema::hasColumn('centros_custo', 'descricao')) {
                DB::statement('ALTER TABLE centros_custo CHANGE nome descricao VARCHAR(255)');
                $log[] = '✅ Coluna "nome" renomeada para "descricao".';
            }
            if (!Schema::hasColumn('centros_custo', 'codigo')) {
                Schema::table('centros_custo', function ($table) { $table->string('codigo')->nullable(); });
                $log[] = '✅ Coluna "codigo" criada.';
            }
            if (!Schema::hasColumn('centros_custo', 'ativo')) {
                Schema::table('centros_custo', function ($table) { $table->boolean('ativo')->default(true); });
                $log[] = '✅ Coluna "ativo" criada.';
            }
        }
        return response()->json(['status' => 'Concluído', 'log' => $log]);
    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage()], 500);
    }
});


// Rota para ver o erro real (Diagnóstico)
Route::get('/debug-logs', function () {
    $logFile = storage_path('logs/laravel.log');
    if (!file_exists($logFile)) {
        return "Arquivo de log não encontrado.";
    }
    
    // Lê as últimas 50 linhas do log
    $lines = file($logFile);
    $lastLines = array_slice($lines, -50);
    
    return response()->json([
        'status' => 'Debug Logs',
        'last_errors' => $lastLines
    ]);
});

Route::get('/fix-database-manual', function () {
    $log = [];
    
    try {
        // 1. CORREÇÃO CRÍTICA: CONFIGURACOES
        if (!Schema::hasTable('configuracoes')) {
            Schema::create('configuracoes', function ($table) {
                $table->id();
                $table->string('chave')->unique();
                $table->text('valor')->nullable();
                $table->string('descricao')->nullable();
                $table->timestamps();
            });
            $log[] = '✅ Tabela "configuracoes" CRIADA (estava faltando).';
        }

        // Adicionar campos de segurança se faltarem
        Schema::table('configuracoes', function ($table) {
            if (!Schema::hasColumn('configuracoes', 'certificado_digital_path')) {
                $table->text('certificado_digital_path')->nullable()->after('valor');
                $table->string('certificado_senha')->nullable()->after('certificado_digital_path');
                $table->string('prefeitura_usuario')->nullable()->after('certificado_senha');
                $table->string('prefeitura_senha')->nullable()->after('prefeitura_usuario');
                $table->string('banco_client_id')->nullable()->after('prefeitura_senha');
                $table->string('banco_client_secret')->nullable()->after('banco_client_id');
                $table->text('banco_certificado_crt')->nullable();
                $table->text('banco_certificado_key')->nullable();
                // Retorno para log
            }
        });
        $log[] = '✅ Campos de segurança verificados em "configuracoes".';

        // 2. CORREÇÃO: CENTROS DE CUSTO
        if (!Schema::hasTable('centros_custo')) {
            $log[] = '❌ ERRO CRÍTICO: Tabela centros_custo não existe! (Verifique migrations)';
        } else {
            if (Schema::hasColumn('centros_custo', 'nome') && !Schema::hasColumn('centros_custo', 'descricao')) {
                DB::statement('ALTER TABLE centros_custo CHANGE nome descricao VARCHAR(255)');
                $log[] = '✅ Coluna "nome" renomeada para "descricao".';
            }
            if (!Schema::hasColumn('centros_custo', 'codigo')) {
                Schema::table('centros_custo', function ($table) { $table->string('codigo')->nullable()->after('id'); });
                $log[] = '✅ Coluna "codigo" criada.';
            }
            if (!Schema::hasColumn('centros_custo', 'ativo')) {
                Schema::table('centros_custo', function ($table) { $table->boolean('ativo')->default(true); });
                $log[] = '✅ Coluna "ativo" criada.';
            }
        }

        // 3. CORREÇÃO: PLANO DE CONTAS
        if (!Schema::hasTable('planos_contas')) {
            $log[] = '❌ ERRO CRÍTICO: Tabela planos_contas não existe!';
        } else {
            Schema::table('planos_contas', function ($table) {
                if (!Schema::hasColumn('planos_contas', 'natureza')) {
                    $table->string('natureza')->nullable()->after('tipo');
                }
                if (!Schema::hasColumn('planos_contas', 'conta_contabil')) {
                    $table->string('conta_contabil')->nullable();
                }
                if (!Schema::hasColumn('planos_contas', 'analitica')) {
                    $table->boolean('analitica')->default(true);
                }
                // Verificação da coluna conta_pai_id
                if (!Schema::hasColumn('planos_contas', 'conta_pai_id')) {
                    $table->unsignedBigInteger('conta_pai_id')->nullable()->after('analitica');
                    $log[] = '✅ Coluna "conta_pai_id" criada.';
                }
            });
            $log[] = '✅ Tabela planos_contas verificada.';
        }

        // Limpeza de Cache
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        $log[] = '🧹 Caches do Laravel limpos.';

    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
    }

    return response()->json(['status' => 'Banco de Dados Corrigido', 'log' => $log]);
});

// CORREÇÃO DE EMERGÊNCIA: SERVIÇOS
Route::get('/fix-servicos-db', function () {
    $log = [];
    try {
        if (!Schema::hasTable('servicos')) {
            return response()->json(['erro' => 'Tabela servicos não existe! Rode as migrations.'], 500);
        }

        Schema::table('servicos', function ($table) use (&$log) {
            // 1. Padronizar 'categoria' -> 'tipo_servico'
            if (Schema::hasColumn('servicos', 'categoria') && !Schema::hasColumn('servicos', 'tipo_servico')) {
                // Tenta renomear (requer doctrine/dbal) ou criar nova
                try {
                    DB::statement('ALTER TABLE servicos CHANGE categoria tipo_servico VARCHAR(255)');
                    $log[] = '✅ Coluna "categoria" renomeada para "tipo_servico".';
                } catch (\Exception $e) {
                    $table->string('tipo_servico')->default('exame')->after('descricao');
                    $log[] = '✅ Coluna "tipo_servico" criada (não foi possível renomear).';
                }
            } elseif (!Schema::hasColumn('servicos', 'tipo_servico')) {
                $table->string('tipo_servico')->default('exame')->after('descricao');
                $log[] = '✅ Coluna "tipo_servico" criada.';
            }

            // 2. Garantir Campos Fiscais
            if (!Schema::hasColumn('servicos', 'cnae')) {
                $table->string('cnae')->nullable()->after('valor_unitario');
                $log[] = '✅ Coluna "cnae" criada.';
            }
            if (!Schema::hasColumn('servicos', 'codigo_servico_municipal')) {
                $table->string('codigo_servico_municipal')->nullable()->after('cnae');
                $log[] = '✅ Coluna "codigo_servico_municipal" criada.';
            }
            if (!Schema::hasColumn('servicos', 'aliquota_iss')) {
                $table->decimal('aliquota_iss', 5, 2)->nullable()->after('codigo_servico_municipal');
                $log[] = '✅ Coluna "aliquota_iss" criada.';
            }
            
            // 3. Garantir Status
            if (!Schema::hasColumn('servicos', 'ativo')) {
                $table->boolean('ativo')->default(true);
                $log[] = '✅ Coluna "ativo" criada.';
            }
        });

        // Limpar Cache
        \Illuminate\Support\Facades\Artisan::call('optimize:clear');
        $log[] = '🧹 Cache limpo.';

    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage()], 500);
    }

    return response()->json(['status' => 'Tabela Serviços Corrigida', 'log' => $log]);
});



Route::prefix('cadastros')->group(function () {
    // ... outras rotas
    Route::apiResource('clientes', ClienteController::class);
    
    // Nova rota para análise inteligente de arquivo (antes de salvar)
    Route::post('clientes/analisar-importacao', [ClienteController::class, 'analisarImportacao']);
    
    // Rota para efetivar a importação após conferência
    Route::post('clientes/confirmar-importacao', [ClienteController::class, 'confirmarImportacao']);
});