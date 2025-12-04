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
use App\Http\Controllers\Api\Contabilidade\LancamentoContabilController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\N8nController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DespesaController;
use App\Http\Controllers\Api\ConfiguracaoController;
use App\Http\Controllers\Api\OrdemServicoController;
use App\Http\Controllers\Api\FaturamentoController;


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
        Route::apiResource('clientes', ClienteController::class);
        Route::post('clientes/importar', [ClienteController::class, 'importarLote']);
        Route::post('clientes/sincronizar-soc', [ClienteController::class, 'sincronizarSoc']);
        Route::post('clientes/analisar-importacao', [ClienteController::class, 'analisarImportacao']);
        Route::post('clientes/confirmar-importacao', [ClienteController::class, 'confirmarImportacao']);
        
        Route::apiResource('servicos', ServicoController::class);
        Route::apiResource('planos-contas', PlanoContaController::class);
        Route::apiResource('centros-custo', CentroCustoController::class);
    });

    // Fornecedores
    Route::apiResource('fornecedores', FornecedorController::class);

    // ========== FATURAMENTO INTELIGENTE ==========
    Route::prefix('faturamento')->group(function () {
        Route::get('faturas', [FaturaController::class, 'index']);
        Route::post('faturas', [FaturaController::class, 'store']);
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
        Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
        Route::post('titulos/{id}/registrar-boleto', [TituloController::class, 'registrarBoleto']);
        Route::get('aging', [TituloController::class, 'relatorioAging']);
    });

    // ========== CONTAS A PAGAR (Despesas) ==========
    Route::prefix('contas-pagar')->group(function () {
        Route::get('despesas', [DespesaController::class, 'index']);
        Route::post('despesas', [DespesaController::class, 'store']);
        Route::post('despesas/analisar-documento', [DespesaController::class, 'analisarDocumento']);
        Route::post('despesas/{id}/pagar', [DespesaController::class, 'pagar']);
    });

    // ========== CONTABILIDADE ==========
    Route::prefix('contabilidade')->group(function () {
        Route::get('lancamentos', [LancamentoContabilController::class, 'index']);
        Route::get('balancete', [LancamentoContabilController::class, 'balancete']);
        Route::get('dre-real', [LancamentoContabilController::class, 'dreReal']);
        Route::post('processar-titulo/{id}', [LancamentoContabilController::class, 'processarTitulo']);

        Route::post('livro-razao/auditar-ia', [LancamentoContabilController::class, 'auditarIa']);
        Route::post('livro-razao/{id}/aprovar-ia', [LancamentoContabilController::class, 'aprovarIa']);
        Route::post('livro-razao/{id}/revisar-ia', [LancamentoContabilController::class, 'revisarIa']);

        Route::get('livro-razao/export-ofx', [LancamentoContabilController::class, 'exportOfx']);
        Route::get('livro-razao/export-excel', [LancamentoContabilController::class, 'exportExcel']);
    });

    Route::apiResource('lancamentos-contabeis', LancamentoContabilController::class)->only(['index', 'store', 'show']);

    // ========== COBRANÇAS ==========
    Route::prefix('cobrancas')->group(function () {
        Route::get('inadimplentes', [CobrancaController::class, 'inadimplentes']);
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


// ============================================
// ROTAS DE MANUTENÇÃO (Públicas - usar com cuidado)
// ============================================

Route::get('/criar-admin-teste', function () {
    try {
        $email = 'papalino@papalino.com';
        $password = 'papalino';

        User::where('email', $email)->delete();

        $user = User::create([
            'name' => 'Papalino',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        return response()->json([
            'sucesso' => true, 
            'mensagem' => "Utilizador criado! Login: $email | Senha: $password"
        ]);
    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage()], 500);
    }
});

Route::get('/fix-chat-messages', function () {
    $log = [];
    
    try {
        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function ($table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('session_id')->index();
                $table->enum('role', ['user', 'assistant', 'system'])->default('user');
                $table->longText('content');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
            $log[] = '✅ Tabela chat_messages criada.';
        } else {
            $log[] = '📋 Tabela chat_messages já existe. Verificando colunas...';
            
            if (!Schema::hasColumn('chat_messages', 'session_id')) {
                Schema::table('chat_messages', function ($table) {
                    $table->string('session_id')->nullable()->after('user_id');
                });
                $log[] = '✅ Coluna session_id adicionada.';
            }
            
            if (!Schema::hasColumn('chat_messages', 'role')) {
                Schema::table('chat_messages', function ($table) {
                    $table->string('role')->default('user')->after('session_id');
                });
                $log[] = '✅ Coluna role adicionada.';
            }
            
            if (!Schema::hasColumn('chat_messages', 'content')) {
                Schema::table('chat_messages', function ($table) {
                    $table->longText('content')->nullable()->after('role');
                });
                $log[] = '✅ Coluna content adicionada.';
            }
            
            if (!Schema::hasColumn('chat_messages', 'metadata')) {
                Schema::table('chat_messages', function ($table) {
                    $table->json('metadata')->nullable()->after('content');
                });
                $log[] = '✅ Coluna metadata adicionada.';
            }
        }
        
        $colunas = Schema::getColumnListing('chat_messages');
        $log[] = '📊 Colunas atuais: ' . implode(', ', $colunas);
        
    } catch (\Exception $e) {
        $log[] = '❌ Erro: ' . $e->getMessage();
    }
    
    return response()->json([
        'status' => 'Verificação concluída',
        'log' => $log
    ]);
});

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
                Schema::table('centros_custo', function ($table) { 
                    $table->string('codigo')->nullable(); 
                });
                $log[] = '✅ Coluna "codigo" criada.';
            }
            if (!Schema::hasColumn('centros_custo', 'ativo')) {
                Schema::table('centros_custo', function ($table) { 
                    $table->boolean('ativo')->default(true); 
                });
                $log[] = '✅ Coluna "ativo" criada.';
            }
            $log[] = '✅ Tabela centros_custo verificada.';
        }

        if (!Schema::hasTable('planos_contas')) {
            $log[] = '❌ ERRO CRÍTICO: Tabela planos_contas não existe!';
        } else {
            Schema::table('planos_contas', function ($table) use (&$log) {
                if (!Schema::hasColumn('planos_contas', 'tipo')) {
                    $table->enum('tipo', ['receita', 'despesa', 'ativo', 'passivo'])->default('despesa');
                    $log[] = '✅ Coluna "tipo" criada.';
                }
                if (!Schema::hasColumn('planos_contas', 'ativo')) {
                    $table->boolean('ativo')->default(true);
                    $log[] = '✅ Coluna "ativo" criada.';
                }
                if (!Schema::hasColumn('planos_contas', 'conta_pai_id')) {
                    $table->unsignedBigInteger('conta_pai_id')->nullable();
                    $log[] = '✅ Coluna "conta_pai_id" criada.';
                }
            });
            $log[] = '✅ Tabela planos_contas verificada.';
        }

        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        $log[] = '🧹 Caches do Laravel limpos.';

    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
    }

    return response()->json(['status' => 'Banco de Dados Corrigido', 'log' => $log]);
});

Route::get('/fix-servicos-db', function () {
    $log = [];
    try {
        if (!Schema::hasTable('servicos')) {
            return response()->json(['erro' => 'Tabela servicos não existe! Rode as migrations.'], 500);
        }

        Schema::table('servicos', function ($table) use (&$log) {
            if (Schema::hasColumn('servicos', 'categoria') && !Schema::hasColumn('servicos', 'tipo_servico')) {
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
            
            if (!Schema::hasColumn('servicos', 'ativo')) {
                $table->boolean('ativo')->default(true);
                $log[] = '✅ Coluna "ativo" criada.';
            }
        });

        \Illuminate\Support\Facades\Artisan::call('optimize:clear');
        $log[] = '🧹 Cache limpo.';

    } catch (\Exception $e) {
        return response()->json(['erro' => $e->getMessage()], 500);
    }

    return response()->json(['status' => 'Tabela Serviços Corrigida', 'log' => $log]);
});
