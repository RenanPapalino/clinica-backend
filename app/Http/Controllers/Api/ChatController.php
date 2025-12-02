<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\Servico;
use App\Models\Funcionario;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\OrdemServicoRateio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    // ========================================
    // ENDPOINT PRINCIPAL: ENVIAR MENSAGEM
    // ========================================

    public function enviarMensagem(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
            }

            $sessionId = $request->input('session_id', 'session_' . $user->id);
            $mensagem = trim($request->input('mensagem', ''));
            $tipoProcessamento = $request->input('tipo_processamento', 'auto');
            $arquivoData = null;

            // 1. Processar Upload de Arquivo
            if ($request->hasFile('arquivo')) {
                $arquivo = $request->file('arquivo');
                
                if ($arquivo->getSize() > self::MAX_FILE_SIZE) {
                    return response()->json([
                        'success' => false, 
                        'message' => 'Arquivo muito grande (Máx 10MB).'
                    ], 422);
                }
                
                $arquivoData = [
                    'nome'      => $arquivo->getClientOriginalName(),
                    'extensao'  => strtolower($arquivo->getClientOriginalExtension()),
                    'mime_type' => $arquivo->getClientMimeType(),
                    'tamanho'   => $arquivo->getSize(),
                    'base64'    => base64_encode(file_get_contents($arquivo->getRealPath())),
                ];
            }

            if ($mensagem === '' && !$arquivoData) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Envie uma mensagem ou arquivo.'
                ], 422);
            }

            // 2. Salvar mensagem do usuário
            $conteudoLog = $arquivoData 
                ? "[Arquivo: {$arquivoData['nome']}] " . $mensagem 
                : $mensagem;
            
            ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'user',
                'content'    => $conteudoLog,
                'session_id' => $sessionId,
                'metadata'   => $arquivoData ? ['file_name' => $arquivoData['nome']] : null
            ]);

            // 3. Enviar para N8N
            $respostaIa = $this->enviarParaN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento);

            // 4. Salvar resposta da IA
            $chatMessage = ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'assistant',
                'content'    => $respostaIa['mensagem'], 
                'session_id' => $sessionId,
                'metadata'   => $respostaIa['dados_estruturados'] ?? null
            ]);

            // 5. Retornar resposta
            return response()->json([
                'success'            => true,
                'id'                 => $chatMessage->id,
                'role'               => $chatMessage->role,
                'content'            => $chatMessage->content,
                'created_at'         => $chatMessage->created_at->toISOString(),
                'dados_estruturados' => $respostaIa['dados_estruturados'] ?? null,
                'acao_sugerida'      => $respostaIa['acao_sugerida'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('Erro ChatController: ' . $e->getMessage() . ' | Linha: ' . $e->getLine());
            return response()->json([
                'success' => false, 
                'message' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========================================
    // COMUNICAÇÃO COM N8N
    // ========================================

    private function enviarParaN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento): array
    {
        if ($arquivoData) {
            $webhookUrl = env('N8N_WEBHOOK_URL');
            $timeout = 600;
            $rotaNome = "ARQUIVO";
        } else {
            $webhookUrl = env('N8N_WEBHOOK_CHAT_URL');
            $timeout = 120;
            $rotaNome = "CHAT";
        }
        
        if (!$webhookUrl) {
            return [
                'mensagem' => "⚠️ Webhook N8N ({$rotaNome}) não configurado.",
                'dados_estruturados' => null
            ];
        }

        try {
            $payload = [
                'mensagem'           => $mensagem,
                'user_id'            => $user->id,
                'user_name'          => $user->name,
                'user_email'         => $user->email ?? null,
                'session_id'         => $sessionId,
                'tipo_processamento' => $tipoProcessamento,
                'timestamp'          => now()->toISOString(),
            ];

            if ($arquivoData) {
                $payload['arquivo'] = $arquivoData;
            }

            Log::info("📤 Enviando para N8N [{$rotaNome}]...");

            $response = Http::timeout($timeout)
                ->connectTimeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ])
                ->post($webhookUrl, $payload);

            if (!$response->successful()) {
                return [
                    'mensagem' => "❌ Erro ao processar (HTTP {$response->status()}).",
                    'dados_estruturados' => null
                ];
            }

            $body = $response->body();

            if (empty($body)) {
                return [
                    'mensagem' => "⚠️ O N8N respondeu vazio. Configure: Respond When Last Node Finishes.",
                    'dados_estruturados' => null
                ];
            }

            $json = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                if (str_contains($body, 'Workflow started')) {
                    return [
                        'mensagem' => "⚠️ Configure N8N: Webhook > Respond > When Last Node Finishes",
                        'dados_estruturados' => null
                    ];
                }
                return ['mensagem' => $body, 'dados_estruturados' => null];
            }

            Log::info("📥 Resposta N8N recebida", ['keys' => array_keys($json)]);

            return $this->processarRespostaN8n($json);

        } catch (\Exception $e) {
            Log::error('Exceção N8N: ' . $e->getMessage());
            return [
                'mensagem' => '❌ Falha de conexão: ' . $e->getMessage(),
                'dados_estruturados' => null
            ];
        }
    }

    // ========================================
    // PROCESSAMENTO DE RESPOSTA N8N
    // ========================================

    private function processarRespostaN8n($data): array
    {
        if (is_string($data)) {
            return $this->extrairJsonDeString($data);
        }

        // Se é lista de resultados do N8N
        if (is_array($data) && array_is_list($data) && count($data) > 0) {
            $first = $data[0];
            
            if (isset($first['output']) || isset($first['message']) || isset($first['text'])) {
                return $this->processarRespostaN8n($first);
            }
            if (isset($first['json'])) {
                return $this->processarRespostaN8n($first['json']);
            }
            
            return $this->formatarResposta($data);
        }

        // Tentar achar conteúdo encapsulado
        $chavesConteudo = ['output', 'message', 'response', 'data', 'json', 'text', 'resultado'];
        
        foreach ($chavesConteudo as $chave) {
            if (isset($data[$chave])) {
                $conteudo = $data[$chave];
                
                if (is_string($conteudo) && (
                    str_starts_with(trim($conteudo), '{') || 
                    str_starts_with(trim($conteudo), '[')
                )) {
                    return $this->extrairJsonDeString($conteudo);
                }
                
                if (is_array($conteudo)) {
                    return $this->formatarResposta($conteudo);
                }
                
                if (is_string($conteudo)) {
                    return ['mensagem' => $conteudo, 'dados_estruturados' => null];
                }
            }
        }

        return $this->formatarResposta($data);
    }

    private function extrairJsonDeString(string $texto): array
    {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $texto, $matches)) {
            $json = json_decode(trim($matches[1]), true);
            if ($json) {
                return $this->formatarResposta($json);
            }
        }
        
        $json = json_decode($texto, true);
        if ($json) {
            return $this->formatarResposta($json);
        }

        return ['mensagem' => $texto, 'dados_estruturados' => null];
    }

    // ========================================
    // FORMATAÇÃO DE RESPOSTA
    // ========================================

    private function formatarResposta($data): array
    {
        $dadosMapeados = [];
        $textoMensagem = null;
        $tipoDetectado = null;
        $colunas = [];
        $metadata = [];

        if (is_array($data)) {
            // Extrair dados mapeados primeiro
            $dadosMapeados = $this->extrairDadosMapeados($data);
            
            // Detectar tipo baseado nos dados extraídos
            $tipoDetectado = $data['tipo'] ?? null;
            
            // Se tipo é generico ou não definido, detectar automaticamente pelos campos
            if (empty($tipoDetectado) || $tipoDetectado === 'generico') {
                $tipoDetectado = $this->detectarTipoPelosCampos($dadosMapeados);
                Log::info("🔍 Tipo detectado automaticamente: {$tipoDetectado}");
            }
            
            if (!empty($dadosMapeados)) {
                $colunas = $this->definirColunas($tipoDetectado, $dadosMapeados);
            }

            $textoMensagem = $this->extrairTextoMensagem($data);
            
            $metadata = [
                'empresa_nome' => $data['empresa_nome'] ?? $data['metadata']['empresa_nome'] ?? null,
                'periodo'      => $data['periodo'] ?? $data['metadata']['periodo'] ?? null,
                'total_valor'  => $data['total_valor'] ?? $data['metadata']['total_valor'] ?? null,
                'cliente_id'   => $data['cliente_id'] ?? $data['metadata']['cliente_id'] ?? null,
            ];
        }

        if (!empty($dadosMapeados)) {
            $acaoSugerida = $this->definirAcaoSugerida($tipoDetectado);
            
            $mensagens = [
                'clientes'            => "📋 Encontrei **%d cliente(s)** para importação.",
                'fatura_titulos'      => "💰 Processada fatura com **%d título(s)**.",
                'fatura_funcionarios' => "👥 Encontrei **%d registro(s) de funcionários/exames**.",
                'funcionarios'        => "👤 Encontrei **%d funcionário(s)** para importação.",
                'servicos'            => "🔧 Encontrei **%d serviço(s)** para importação.",
            ];
            
            $mensagemPreview = $textoMensagem ?? sprintf(
                $mensagens[$tipoDetectado] ?? "✅ Processados **%d** registro(s).",
                count($dadosMapeados)
            );
            
            Log::info("📊 Formatando resposta", [
                'tipo' => $tipoDetectado,
                'acao' => $acaoSugerida,
                'registros' => count($dadosMapeados)
            ]);
            
            return [
                'mensagem' => $mensagemPreview,
                'dados_estruturados' => [
                    'sucesso'         => true,
                    'tipo'            => $tipoDetectado,
                    'dados_mapeados'  => $dadosMapeados,
                    'colunas'         => $colunas,
                    'acao_sugerida'   => $acaoSugerida,
                    'total_registros' => count($dadosMapeados),
                    'confianca'       => $data['confianca'] ?? 1.0,
                    'erros'           => $data['erros'] ?? [],
                    'avisos'          => $data['avisos'] ?? [],
                    'metadata'        => $metadata,
                ],
                'acao_sugerida' => $acaoSugerida,
            ];
        }

        if ($textoMensagem) {
            return ['mensagem' => $textoMensagem, 'dados_estruturados' => null];
        }

        return [
            'mensagem' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'dados_estruturados' => null
        ];
    }

    private function extrairDadosMapeados(array $data): array
    {
        if (array_is_list($data) && count($data) > 0 && is_array($data[0])) {
            return $data;
        }
        
        $chavesDados = [
            'dados_mapeados', 'registros', 'data', 'items', 'rows', 
            'clientes', 'servicos', 'funcionarios', 'empresas',
            'lista', 'fatura', 'titulos', 'exames', 'linhas'
        ];
        
        foreach ($chavesDados as $chave) {
            if (isset($data[$chave]) && is_array($data[$chave]) && count($data[$chave]) > 0) {
                return $data[$chave];
            }
        }

        if (isset($data['empresa']) && is_array($data['empresa'])) {
            return [$data['empresa']];
        }

        return [];
    }

    private function extrairTextoMensagem(array $data): ?string
    {
        $chavesTexto = [
            'mensagem', 'message', 'output', 'text', 'response', 
            'answer', 'content', 'reply', 'texto', 'body', 'resumo'
        ];
        
        foreach ($chavesTexto as $chave) {
            if (!empty($data[$chave]) && is_string($data[$chave])) {
                return $data[$chave];
            }
        }
        
        return null;
    }

    // ========================================
    // DETECÇÃO DE TIPO PELOS CAMPOS
    // ========================================

    /**
     * Detecta o tipo de dados baseado nos campos presentes
     */
    private function detectarTipoPelosCampos(array $dados): string
    {
        if (empty($dados)) {
            return 'generico';
        }

        // Pegar primeiro registro como amostra
        $amostra = $dados[0] ?? [];
        if (!is_array($amostra)) {
            return 'generico';
        }

        // Normalizar nomes das colunas
        $camposNormalizados = [];
        foreach (array_keys($amostra) as $campo) {
            $camposNormalizados[] = $this->normalizarNomeColuna((string) $campo);
        }
        $camposStr = implode(',', $camposNormalizados);

        Log::info("🔎 Campos para detecção: {$camposStr}");

        // CLIENTES: tem CNPJ
        if (str_contains($camposStr, 'cnpj')) {
            return 'clientes';
        }

        // FATURA FUNCIONÁRIOS: tem matricula + (exame ou setor)
        if (str_contains($camposStr, 'matricula') && 
            (str_contains($camposStr, 'exame') || str_contains($camposStr, 'setor') || str_contains($camposStr, 'nome'))) {
            return 'fatura_funcionarios';
        }

        // FATURA TÍTULOS: tem produto/serviço ou vidas
        if (str_contains($camposStr, 'produtoservico') || 
            str_contains($camposStr, 'produto') ||
            str_contains($camposStr, 'vidasativas') ||
            str_contains($camposStr, 'vidas') ||
            (str_contains($camposStr, 'total') && str_contains($camposStr, 'servico'))) {
            return 'fatura_titulos';
        }

        // FUNCIONÁRIOS: tem CPF
        if (str_contains($camposStr, 'cpf')) {
            return 'funcionarios';
        }

        // SERVIÇOS: tem código TUSS ou código + descrição
        if (str_contains($camposStr, 'tuss') || 
            (str_contains($camposStr, 'codigo') && str_contains($camposStr, 'descricao'))) {
            return 'servicos';
        }

        return 'generico';
    }

    private function normalizarNomeColuna(string $nome): string
    {
        $nome = strtolower($nome);
        $nome = str_replace([' ', '_', '-', '.', '/', '\\'], '', $nome);
        return $this->removerAcentos($nome);
    }

    private function removerAcentos(string $string): string
    {
        return preg_replace(
            ['/[áàãâä]/u', '/[éèêë]/u', '/[íìîï]/u', '/[óòõôö]/u', '/[úùûü]/u', '/[ç]/u'],
            ['a', 'e', 'i', 'o', 'u', 'c'],
            $string
        );
    }

    // ========================================
    // DEFINIÇÃO DE COLUNAS
    // ========================================

    private function definirColunas(?string $tipo, array $dados): array
    {
        $primeiroRegistro = $dados[0] ?? [];
        $todasColunas = array_keys($primeiroRegistro);
        
        $mapaColunasReais = [];
        foreach ($todasColunas as $coluna) {
            $normalizada = $this->normalizarNomeColuna($coluna);
            $mapaColunasReais[$normalizada] = $coluna;
        }

        $colunasPreferidas = match($tipo) {
            'clientes' => [
                ['key' => 'razaosocial', 'label' => 'Razão Social', 'width' => 200],
                ['key' => 'nomefantasia', 'label' => 'Nome Fantasia', 'width' => 150],
                ['key' => 'cnpj', 'label' => 'CNPJ', 'width' => 150],
                ['key' => 'email', 'label' => 'E-mail', 'width' => 180],
                ['key' => 'telefone', 'label' => 'Telefone', 'width' => 120],
                ['key' => 'cidade', 'label' => 'Cidade', 'width' => 120],
                ['key' => 'uf', 'label' => 'UF', 'width' => 50],
            ],
            'fatura_funcionarios' => [
                ['key' => 'nome', 'label' => 'Funcionário', 'width' => 200],
                ['key' => 'matricula', 'label' => 'Matrícula', 'width' => 100],
                ['key' => 'setor', 'label' => 'Setor', 'width' => 120],
                ['key' => 'situacao', 'label' => 'Situação', 'width' => 80],
                ['key' => 'exame', 'label' => 'Exame', 'width' => 180],
                ['key' => 'tipo', 'label' => 'Tipo', 'width' => 60],
                ['key' => 'dtexame', 'label' => 'Data Exame', 'width' => 100],
                ['key' => 'vlcobrar', 'label' => 'Valor (R$)', 'width' => 100, 'align' => 'right', 'format' => 'currency'],
            ],
            'fatura_titulos' => [
                ['key' => 'produtoservico', 'label' => 'Produto/Serviço', 'width' => 250],
                ['key' => 'datacobranca', 'label' => 'Data Cobrança', 'width' => 120],
                ['key' => 'vidasativas', 'label' => 'Vidas', 'width' => 80, 'align' => 'center'],
                ['key' => 'valorvida', 'label' => 'Valor/Vida', 'width' => 100, 'align' => 'right', 'format' => 'currency'],
                ['key' => 'totalreais', 'label' => 'Total (R$)', 'width' => 120, 'align' => 'right', 'format' => 'currency'],
            ],
            default => []
        };

        if (empty($colunasPreferidas)) {
            return array_map(fn($key) => [
                'key'   => $key,
                'label' => $this->formatarLabelColuna($key),
                'width' => 150
            ], array_slice($todasColunas, 0, 8));
        }

        $colunasFinais = [];
        foreach ($colunasPreferidas as $col) {
            $keyNormalizada = $col['key'];
            
            if (isset($mapaColunasReais[$keyNormalizada])) {
                $col['key'] = $mapaColunasReais[$keyNormalizada];
                $colunasFinais[] = $col;
            }
        }

        if (empty($colunasFinais)) {
            return array_map(fn($key) => [
                'key'   => $key,
                'label' => $this->formatarLabelColuna($key),
                'width' => 150
            ], array_slice($todasColunas, 0, 8));
        }

        return $colunasFinais;
    }

    private function formatarLabelColuna(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    private function definirAcaoSugerida(?string $tipo): string
    {
        return match($tipo) {
            'clientes'            => 'importar_clientes',
            'fatura_titulos'      => 'gerar_fatura',
            'fatura_funcionarios' => 'gerar_os_detalhada',
            'funcionarios'        => 'importar_funcionarios',
            'servicos'            => 'importar_servicos',
            default               => 'importar_generico'
        };
    }

    // ========================================
    // ENDPOINTS AUXILIARES
    // ========================================

    public function historico(Request $request)
    {
        $user = $request->user();
        $sessionId = $request->input('session_id', 'session_' . $user->id);
        
        $mensagens = ChatMessage::where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get()
            ->map(function ($msg) {
                return [
                    'id'                 => $msg->id,
                    'role'               => $msg->role,
                    'content'            => $msg->content,
                    'created_at'         => $msg->created_at->toISOString(),
                    'dados_estruturados' => $msg->metadata,
                ];
            });
            
        return response()->json(['success' => true, 'data' => $mensagens]);
    }

    public function limparHistorico(Request $request)
    {
        $user = $request->user();
        $sessionId = $request->input('session_id', 'session_' . $user->id);
        
        ChatMessage::where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->delete();
            
        return response()->json(['success' => true, 'message' => 'Histórico limpo.']);
    }

    // ========================================
    // CONFIRMAÇÃO DE AÇÃO (IMPORTAÇÃO)
    // ========================================

    public function confirmarAcao(Request $request)
    {
        $acao = $request->input('acao') ?? $request->input('tipo');
        $dados = $request->input('dados', []);
        $metadata = $request->input('metadata', []);
        
        // Garantir que metadata é array
        if (!is_array($metadata)) {
            $metadata = [];
        }
        
        if (empty($dados) || !is_array($dados)) {
            return response()->json([
                'success' => false, 
                'message' => 'Nenhum dado para importar.'
            ], 422);
        }

        Log::info("📥 ===== CONFIRMANDO AÇÃO =====");
        Log::info("📥 Ação recebida: {$acao}");
        Log::info("📥 Total de registros: " . count($dados));
        Log::info("📥 Metadata: " . json_encode($metadata));
        Log::info("📥 Primeiro registro: " . json_encode($dados[0] ?? []));

        // Se ação for genérica, tentar detectar pelo conteúdo dos dados
        if ($acao === 'importar_generico' || $acao === 'generico' || empty($acao)) {
            $tipoDetectado = $this->detectarTipoPelosCampos($dados);
            $acao = $this->definirAcaoSugerida($tipoDetectado);
            Log::info("🔄 Ação reclassificada para: {$acao} (tipo: {$tipoDetectado})");
        }

        DB::beginTransaction();
        try {
            Log::info("🔧 Executando ação: {$acao}");
            
            $resultado = match($acao) {
                'importar_clientes', 'clientes' 
                    => $this->importarClientes($dados),
                
                'importar_funcionarios', 'funcionarios' 
                    => $this->importarFuncionarios($dados),
                
                'importar_servicos', 'servicos' 
                    => $this->importarServicos($dados),
                
                'gerar_fatura', 'fatura', 'fatura_titulos' 
                    => $this->gerarOrdemServico($dados, $metadata),
                
                'gerar_os_detalhada', 'fatura_funcionarios', 'importar_fatura', 'relatorio_fatura' 
                    => $this->gerarOrdemServicoDetalhada($dados, $metadata),
                
                default => $this->tentarImportacaoAutomatica($dados, $metadata)
            };

            DB::commit();
            
            Log::info("✅ Ação concluída com sucesso: " . ($resultado['message'] ?? 'OK'));
            
            return response()->json([
                'success'  => true,
                'message'  => $resultado['message'],
                'detalhes' => $resultado['detalhes'] ?? null
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ ERRO ao confirmar ação: " . $e->getMessage());
            Log::error("❌ Linha: " . $e->getLine());
            Log::error("❌ Arquivo: " . $e->getFile());
            
            return response()->json([
                'success' => false, 
                'message' => 'Erro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tenta importação automática baseada nos campos dos dados
     */
    private function tentarImportacaoAutomatica(array $dados, array $metadata): array
    {
        Log::info("🤖 Tentando importação automática...");
        
        $tipo = $this->detectarTipoPelosCampos($dados);
        
        Log::info("🤖 Tipo detectado automaticamente: {$tipo}");
        
        return match($tipo) {
            'clientes'            => $this->importarClientes($dados),
            'fatura_titulos'      => $this->gerarOrdemServico($dados, $metadata),
            'fatura_funcionarios' => $this->gerarOrdemServicoDetalhada($dados, $metadata),
            'funcionarios'        => $this->importarFuncionarios($dados),
            'servicos'            => $this->importarServicos($dados),
            default               => [
                'message' => "⚠️ Não foi possível identificar o tipo de dados para importação. " .
                            "Campos encontrados: " . implode(', ', array_keys($dados[0] ?? [])),
                'detalhes' => ['tipo_detectado' => $tipo, 'campos' => array_keys($dados[0] ?? [])]
            ]
        };
    }

    // ========================================
    // IMPORTAR CLIENTES
    // ========================================

    private function importarClientes(array $dados): array
    {
        Log::info("👥 Iniciando importação de clientes: " . count($dados) . " registros");
        
        $criados = 0;
        $atualizados = 0;
        $erros = [];

        foreach ($dados as $index => $d) {
            try {
                if (!is_array($d)) {
                    $erros[] = "Registro {$index}: não é array";
                    continue;
                }

                $cnpjRaw = $this->getValorFlexivel($d, 'cnpj', 'CNPJ');
                $cnpj = preg_replace('/\D/', '', (string) $this->converterParaString($cnpjRaw));
                
                Log::info("👥 Processando registro {$index}: CNPJ={$cnpj}");
                
                if (empty($cnpj)) {
                    $erros[] = "Registro {$index}: CNPJ vazio";
                    continue;
                }

                if (strlen($cnpj) !== 14) {
                    $erros[] = "Registro {$index}: CNPJ inválido ({$cnpj})";
                    continue;
                }

                $cliente = Cliente::where('cnpj', $cnpj)->first();
                
                $dadosCliente = [
                    'cnpj'          => $cnpj,
                    'razao_social'  => $this->converterParaString($this->getValorFlexivel($d, 'razao_social', 'razaosocial', 'RAZAO SOCIAL', 'razão social')) ?? 'Cliente ' . $cnpj,
                    'nome_fantasia' => $this->converterParaString($this->getValorFlexivel($d, 'nome_fantasia', 'nomefantasia', 'NOME FANTASIA', 'nome fantasia')),
                    'email'         => $this->converterParaString($this->getValorFlexivel($d, 'email', 'EMAIL', 'e-mail')),
                    'telefone'      => $this->converterParaString($this->getValorFlexivel($d, 'telefone', 'TELEFONE', 'fone')),
                    'celular'       => $this->converterParaString($this->getValorFlexivel($d, 'celular', 'CELULAR')),
                    'cep'           => preg_replace('/\D/', '', (string) ($this->converterParaString($this->getValorFlexivel($d, 'cep', 'CEP')) ?? '')),
                    'logradouro'    => $this->converterParaString($this->getValorFlexivel($d, 'logradouro', 'LOGRADOURO', 'endereco', 'endereço')),
                    'numero'        => $this->converterParaString($this->getValorFlexivel($d, 'numero', 'NUMERO', 'número')),
                    'bairro'        => $this->converterParaString($this->getValorFlexivel($d, 'bairro', 'BAIRRO')),
                    'cidade'        => $this->converterParaString($this->getValorFlexivel($d, 'cidade', 'CIDADE')),
                    'uf'            => strtoupper((string) ($this->converterParaString($this->getValorFlexivel($d, 'uf', 'UF', 'estado')) ?? '')),
                    'status'        => 'ativo',
                ];

                // Remover valores vazios
                $dadosCliente = array_filter($dadosCliente, fn($v) => $v !== null && $v !== '');
                $dadosCliente['cnpj'] = $cnpj;
                $dadosCliente['status'] = 'ativo';
                
                if (!isset($dadosCliente['razao_social']) || empty($dadosCliente['razao_social'])) {
                    $dadosCliente['razao_social'] = 'Cliente ' . $cnpj;
                }

                Log::info("👥 Dados do cliente: " . json_encode($dadosCliente));

                if ($cliente) {
                    $cliente->update($dadosCliente);
                    $atualizados++;
                    Log::info("👥 Cliente atualizado: ID={$cliente->id}");
                } else {
                    $novoCliente = Cliente::create($dadosCliente);
                    $criados++;
                    Log::info("👥 Cliente criado: ID={$novoCliente->id}");
                }
            } catch (\Exception $e) {
                Log::error("👥 Erro no registro {$index}: " . $e->getMessage());
                $erros[] = "Registro {$index}: " . $e->getMessage();
            }
        }

        $mensagem = "✅ Clientes importados: {$criados} novos, {$atualizados} atualizados.";
        if (count($erros) > 0) {
            $mensagem .= " (" . count($erros) . " erro(s))";
            Log::warning("👥 Erros: " . json_encode($erros));
        }

        Log::info("👥 Importação concluída: {$criados} criados, {$atualizados} atualizados");

        return [
            'message' => $mensagem,
            'detalhes' => compact('criados', 'atualizados', 'erros')
        ];
    }

    private function converterParaString($valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        
        if (is_array($valor)) {
            if (empty($valor)) {
                return null;
            }
            return (string) ($valor[0] ?? implode(', ', array_filter($valor)));
        }
        
        if (is_object($valor)) {
            if (method_exists($valor, '__toString')) {
                return (string) $valor;
            }
            return json_encode($valor);
        }
        
        $str = trim((string) $valor);
        return $str === '' ? null : $str;
    }

    private function importarFuncionarios(array $dados): array
    {
        Log::info("👤 Iniciando importação de funcionários");
        
        $total = 0;
        $erros = [];

        foreach ($dados as $d) {
            try {
                if (!is_array($d)) continue;

                $cpfRaw = $this->getValorFlexivel($d, 'cpf');
                $cpf = preg_replace('/\D/', '', (string) $this->converterParaString($cpfRaw));
                
                if (empty($cpf)) {
                    continue;
                }

                Funcionario::updateOrCreate(
                    ['cpf' => $cpf],
                    [
                        'nome'      => $this->converterParaString($this->getValorFlexivel($d, 'nome', 'Nome')),
                        'matricula' => $this->converterParaString($this->getValorFlexivel($d, 'matricula', 'Matricula')),
                        'cargo'     => $this->converterParaString($this->getValorFlexivel($d, 'cargo', 'Cargo')),
                        'setor'     => $this->converterParaString($this->getValorFlexivel($d, 'setor', 'Setor')),
                        'situacao'  => $this->converterParaString($this->getValorFlexivel($d, 'situacao', 'Situação')) ?? 'Ativo',
                    ]
                );
                $total++;
            } catch (\Exception $e) {
                $erros[] = $e->getMessage();
            }
        }

        return [
            'message' => "✅ {$total} funcionário(s) importado(s).",
            'detalhes' => ['total' => $total, 'erros' => $erros]
        ];
    }

    private function importarServicos(array $dados): array
    {
        Log::info("🔧 Iniciando importação de serviços");
        
        $total = 0;

        foreach ($dados as $d) {
            if (!is_array($d)) continue;

            $codigo = $this->converterParaString($this->getValorFlexivel($d, 'codigo', 'tuss', 'TUSS'));
            
            if (empty($codigo)) {
                continue;
            }

            Servico::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'descricao' => $this->converterParaString($this->getValorFlexivel($d, 'descricao', 'nome', 'Descrição')),
                    'valor'     => $this->parseValor($this->getValorFlexivel($d, 'valor', 'valor_unitario') ?? 0),
                    'unidade'   => $this->converterParaString($this->getValorFlexivel($d, 'unidade')),
                ]
            );
            $total++;
        }

        return [
            'message' => "✅ {$total} serviço(s) importado(s)."
        ];
    }

    // ========================================
    // GERAÇÃO DE ORDEM DE SERVIÇO
    // ========================================

    private function gerarOrdemServico(array $dados, array $metadata): array
    {
        Log::info("📄 Gerando Ordem de Serviço (Títulos)");
        Log::info("📄 Dados recebidos: " . count($dados) . " itens");
        
        $clienteId = $this->identificarCliente($metadata);
        
        $valorTotal = 0;
        $rateio = [];
        $itensValidos = [];

        foreach ($dados as $item) {
            if (!is_array($item)) continue;

            // Tentar múltiplas variações do campo produto/serviço
            $descricao = $this->converterParaString(
                $this->getValorFlexivel($item, 
                    'produto_servico', 'Produto/Serviço', 'produtoservico',
                    'produto servico', 'descricao', 'servico', 'produto'
                )
            ) ?? '';
            
            Log::info("📄 Item: {$descricao}");
            
            // Ignorar linha de TOTAL
            $descricaoUpper = strtoupper(trim($descricao));
            if ($descricaoUpper === 'TOTAL R$' || str_starts_with($descricaoUpper, 'TOTAL')) {
                Log::info("📄 Ignorando linha de TOTAL");
                continue;
            }

            // Tentar múltiplas variações do campo valor
            $valorItem = $this->parseValor(
                $this->getValorFlexivel($item, 
                    'total_reais', 'Total R$', 'totalreais', 'total',
                    'valor', 'valor_total', 'valortotal'
                ) ?? 0
            );
            
            Log::info("📄 Valor: {$valorItem}");
            
            if (empty($descricao) && $valorItem == 0) {
                continue;
            }

            $valorTotal += $valorItem;
            $itensValidos[] = $item;
        }

        Log::info("📄 Itens válidos: " . count($itensValidos) . " | Total: {$valorTotal}");

        if (empty($itensValidos)) {
            return [
                'message' => '⚠️ Nenhum item válido encontrado para gerar OS.',
                'detalhes' => ['motivo' => 'Todos os itens foram filtrados']
            ];
        }

        $codigoOs = 'OS-CHAT-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $competencia = $this->converterParaString($metadata['periodo'] ?? null) ?? now()->format('m/Y');

        Log::info("📄 Criando OS: {$codigoOs}");

        $os = OrdemServico::create([
            'cliente_id'    => $clienteId,
            'codigo_os'     => $codigoOs,
            'competencia'   => $competencia,
            'data_emissao'  => now(),
            'valor_total'   => $valorTotal,
            'status'        => 'pendente',
            'observacoes'   => "Importado via Chat IA - " . count($itensValidos) . " título(s)",
        ]);

        Log::info("📄 OS criada: ID={$os->id}");

        foreach ($itensValidos as $item) {
            $descricao = $this->converterParaString(
                $this->getValorFlexivel($item, 
                    'produto_servico', 'Produto/Serviço', 'produtoservico',
                    'produto servico', 'descricao', 'servico', 'produto'
                )
            ) ?? 'Serviço';

            $quantidade = (int)($this->getValorFlexivel($item, 
                'vidas_ativas', 'vidasativas', 'vidas', 'quantidade', 'qtd'
            ) ?? 1) ?: 1;
            
            $valorUnitario = $this->parseValor(
                $this->getValorFlexivel($item, 
                    'valor_por_vida_reais', 'valorporvida', 'valor_unitario', 
                    'valorunitario', 'Valor por Vida R$'
                ) ?? 0
            );
            
            $valorTotalItem = $this->parseValor(
                $this->getValorFlexivel($item, 
                    'total_reais', 'Total R$', 'totalreais', 'total',
                    'valor', 'valor_total', 'valortotal'
                ) ?? 0
            );
            
            if ($valorUnitario == 0 && $quantidade > 0 && $valorTotalItem > 0) {
                $valorUnitario = $valorTotalItem / $quantidade;
            }

            $centroCusto = $this->converterParaString(
                $this->getValorFlexivel($item, 'centro_custo', 'setor', 'unidade')
            ) ?? 'Geral';

            OrdemServicoItem::create([
                'ordem_servico_id' => $os->id,
                'descricao'        => $descricao,
                'quantidade'       => $quantidade,
                'valor_unitario'   => $valorUnitario,
                'valor_total'      => $valorTotalItem,
                'centro_custo'     => $centroCusto,
                'data_realizacao'  => now()->format('Y-m-d'),
            ]);

            if (!isset($rateio[$centroCusto])) {
                $rateio[$centroCusto] = 0;
            }
            $rateio[$centroCusto] += $valorTotalItem;
        }

        foreach ($rateio as $cc => $valor) {
            if ($valor > 0) {
                OrdemServicoRateio::create([
                    'ordem_servico_id' => $os->id,
                    'centro_custo'     => $cc,
                    'valor'            => $valor,
                    'percentual'       => $valorTotal > 0 ? round(($valor / $valorTotal) * 100, 2) : 0,
                ]);
            }
        }

        Log::info("📄 OS finalizada: {$os->codigo_os} | Total: R$ {$valorTotal}");

        return [
            'message' => "✅ Ordem de Serviço #{$os->codigo_os} criada! Total: R$ " . number_format($valorTotal, 2, ',', '.'),
            'detalhes' => [
                'os_id'       => $os->id,
                'codigo_os'   => $os->codigo_os,
                'valor_total' => $valorTotal,
                'itens'       => $os->itens()->count(),
                'rateio'      => $rateio
            ]
        ];
    }

    private function gerarOrdemServicoDetalhada(array $dados, array $metadata): array
    {
        Log::info("📋 Gerando OS Detalhada (Funcionários/Exames)");
        
        $clienteId = $this->identificarCliente($metadata);

        $itensPorExame = [];
        $valorTotal = 0;
        $rateio = [];

        foreach ($dados as $item) {
            if (!is_array($item)) continue;

            $exame = $this->converterParaString(
                $this->getValorFlexivel($item, 'exame', 'Exame', 'descricao', 'servico')
            ) ?? 'Serviço';
            
            $valor = $this->parseValor(
                $this->getValorFlexivel($item, 
                    'valor', 'Vl.Cobrar R$', 'vlcobrar', 'vlcobrarrs',
                    'valor_cobrar', 'total', 'vl cobrar'
                ) ?? 0
            );
            
            $setor = $this->converterParaString(
                $this->getValorFlexivel($item, 'setor', 'Setor', 'centro_custo')
            ) ?? 'Geral';
            
            $funcionario = $this->converterParaString(
                $this->getValorFlexivel($item, 'nome', 'Nome', 'funcionario')
            );
            
            $tipoExame = $this->converterParaString(
                $this->getValorFlexivel($item, 'tipo', 'Tipo')
            ) ?? '';

            $chaveExame = $exame . '|' . $tipoExame;
            if (!isset($itensPorExame[$chaveExame])) {
                $itensPorExame[$chaveExame] = [
                    'descricao'    => $exame . ($tipoExame ? " ({$tipoExame})" : ''),
                    'quantidade'   => 0,
                    'valor_total'  => 0,
                    'funcionarios' => []
                ];
            }
            
            $itensPorExame[$chaveExame]['quantidade']++;
            $itensPorExame[$chaveExame]['valor_total'] += $valor;
            
            if ($funcionario) {
                $itensPorExame[$chaveExame]['funcionarios'][] = $funcionario;
            }

            $valorTotal += $valor;

            if (!isset($rateio[$setor])) {
                $rateio[$setor] = 0;
            }
            $rateio[$setor] += $valor;
        }

        $codigoOs = 'OS-DET-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $competencia = $this->converterParaString($metadata['periodo'] ?? null) ?? now()->format('m/Y');

        $os = OrdemServico::create([
            'cliente_id'    => $clienteId,
            'codigo_os'     => $codigoOs,
            'competencia'   => $competencia,
            'data_emissao'  => now(),
            'valor_total'   => $valorTotal,
            'status'        => 'pendente',
            'observacoes'   => "Importado via Chat IA (Detalhado) - " . count($dados) . " registros",
        ]);

        foreach ($itensPorExame as $item) {
            if ($item['valor_total'] > 0 || $item['quantidade'] > 0) {
                $valorUnitario = $item['quantidade'] > 0 
                    ? $item['valor_total'] / $item['quantidade'] 
                    : 0;
                
                OrdemServicoItem::create([
                    'ordem_servico_id' => $os->id,
                    'descricao'        => $item['descricao'],
                    'quantidade'       => $item['quantidade'],
                    'valor_unitario'   => $valorUnitario,
                    'valor_total'      => $item['valor_total'],
                    'centro_custo'     => 'Consolidado',
                    'data_realizacao'  => now()->format('Y-m-d'),
                ]);
            }
        }

        foreach ($rateio as $cc => $valor) {
            if ($valor > 0) {
                OrdemServicoRateio::create([
                    'ordem_servico_id' => $os->id,
                    'centro_custo'     => $cc,
                    'valor'            => $valor,
                    'percentual'       => $valorTotal > 0 ? round(($valor / $valorTotal) * 100, 2) : 0,
                ]);
            }
        }

        $totalExames = array_sum(array_column($itensPorExame, 'quantidade'));

        Log::info("📋 OS Detalhada criada: {$os->codigo_os}");

        return [
            'message' => "✅ OS #{$os->codigo_os} criada com {$totalExames} exame(s). Total: R$ " . number_format($valorTotal, 2, ',', '.'),
            'detalhes' => [
                'os_id'        => $os->id,
                'codigo_os'    => $os->codigo_os,
                'valor_total'  => $valorTotal,
                'total_exames' => $totalExames,
                'tipos_exame'  => count($itensPorExame),
                'rateio'       => $rateio
            ]
        ];
    }

    // ========================================
    // UTILITÁRIOS
    // ========================================

    private function identificarCliente(array $metadata): ?int
    {
        $clienteId = $metadata['cliente_id'] ?? null;
        
        if ($clienteId) {
            return (int) $clienteId;
        }

        $empresaNome = $this->converterParaString($metadata['empresa_nome'] ?? null);
        
        if ($empresaNome) {
            $cliente = Cliente::where('razao_social', 'like', "%{$empresaNome}%")
                ->orWhere('nome_fantasia', 'like', "%{$empresaNome}%")
                ->first();
            
            return $cliente?->id;
        }

        return null;
    }

    private function getValorFlexivel(array $dados, string ...$chaves): mixed
    {
        foreach ($chaves as $chave) {
            if (isset($dados[$chave])) {
                return $dados[$chave];
            }
        }
        
        $dadosNormalizados = [];
        foreach ($dados as $key => $value) {
            $keyNormalizada = $this->normalizarNomeColuna((string) $key);
            $dadosNormalizados[$keyNormalizada] = $value;
        }
        
        foreach ($chaves as $chave) {
            $chaveNormalizada = $this->normalizarNomeColuna($chave);
            if (isset($dadosNormalizados[$chaveNormalizada])) {
                return $dadosNormalizados[$chaveNormalizada];
            }
        }
        
        return null;
    }

    private function parseValor($valor): float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        
        if (is_array($valor)) {
            $valor = $valor[0] ?? '0';
        }
        
        if (!is_string($valor)) {
            return 0;
        }
        
        // Remover texto como "(Mínimo)"
        $valor = preg_replace('/\s*\([^)]*\)/', '', $valor);
        
        // Tratar formato brasileiro: 1.234,56
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
        $valor = preg_replace('/[^\d.]/', '', $valor);
        
        return (float) $valor;
    }
}