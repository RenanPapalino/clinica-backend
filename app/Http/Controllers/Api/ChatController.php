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

    /**
     * Enviar mensagem para o chatbot
     */
    public function enviarMensagem(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) return response()->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);

            $sessionId = $request->input('session_id', 'session_' . $user->id);
            $mensagem = trim($request->input('mensagem', ''));
            $tipoProcessamento = $request->input('tipo_processamento', 'auto');
            $arquivoData = null;

            // 1. Processar Upload
            if ($request->hasFile('arquivo')) {
                $arquivo = $request->file('arquivo');
                if ($arquivo->getSize() > self::MAX_FILE_SIZE) {
                    return response()->json(['success' => false, 'message' => 'Arquivo muito grande (Máx 10MB).'], 422);
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
                return response()->json(['success' => false, 'message' => 'Envie mensagem ou arquivo.'], 422);
            }

            // 2. Log Mensagem Usuário
            $conteudoLog = $arquivoData ? "[Arquivo: {$arquivoData['nome']}] " . $mensagem : $mensagem;
            
            ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'user',
                'content'    => $conteudoLog,
                'session_id' => $sessionId,
                'metadata'   => $arquivoData ? ['file_name' => $arquivoData['nome']] : null
            ]);

            // 3. Enviar para N8N (e aguardar resposta real)
            $respostaIa = $this->enviarParaN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento);

            // 4. Salvar Resposta IA
            $chatMessage = ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'assistant',
                'content'    => $respostaIa['mensagem'], 
                'session_id' => $sessionId,
                'metadata'   => $respostaIa['dados_estruturados'] ?? null
            ]);

            // Retorno compatível com o FloatingChat (dados no root, não em 'data')
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
            Log::error('Erro ChatController: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Envia para N8N com Headers forçados para JSON
     */
    private function enviarParaN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento): array
    {
        if ($arquivoData) {
            $webhookUrl = env('N8N_WEBHOOK_URL'); 
            $timeout = 600; // 10 min para arquivos
            $rotaNome = "ARQUIVO";
        } else {
            $webhookUrl = env('N8N_WEBHOOK_CHAT_URL');
            $timeout = 120; // 2 min para chat
            $rotaNome = "CHAT";
        }
        
        if (!$webhookUrl) {
            return ['mensagem' => "⚠️ URL ({$rotaNome}) não configurada.", 'dados_estruturados' => null];
        }

        try {
            $payload = [
                'mensagem'           => $mensagem,
                'user_id'            => $user->id,
                'user_name'          => $user->name,
                'session_id'         => $sessionId,
                'tipo_processamento' => $tipoProcessamento,
                'timestamp'          => now()->toISOString(),
            ];

            if ($arquivoData) $payload['arquivo'] = $arquivoData;

            Log::info("Enviando N8N [{$rotaNome}]...", ['user' => $user->id]);

            $response = Http::timeout($timeout)
                ->connectTimeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ])
                ->post($webhookUrl, $payload);

            if (!$response->successful()) {
                return [
                    'mensagem' => "❌ Erro IA (HTTP {$response->status()}).",
                    'dados_estruturados' => null
                ];
            }

            $body = $response->body();

            if (empty($body)) {
                return [
                    'mensagem' => "⚠️ O N8N respondeu com sucesso (200 OK), mas enviou um corpo vazio.", 
                    'dados_estruturados' => null
                ];
            }

            $json = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                if (str_contains($body, 'Workflow started')) {
                    return [
                        'mensagem' => "⚠️ O N8N iniciou o fluxo, mas não esperou a resposta.\n\nConfigure o nó Webhook para 'Respond: When Last Node Finishes'.",
                        'dados_estruturados' => null
                    ];
                }
                return ['mensagem' => $body, 'dados_estruturados' => null];
            }

            return $this->processarRespostaN8n($json);

        } catch (\Exception $e) {
            Log::error('Exceção N8N: ' . $e->getMessage());
            return ['mensagem' => '❌ Falha de conexão: ' . $e->getMessage(), 'dados_estruturados' => null];
        }
    }

    /**
     * Processa a estrutura JSON (Busca Agressiva)
     */
    private function processarRespostaN8n($data): array
    {
        if (is_string($data)) return $this->extrairJsonDeString($data);

        // Se for lista [{}, {}]
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

        // Tenta achar conteúdo encapsulado
        $conteudo = $data['output'] ?? $data['message'] ?? $data['response'] ?? $data['data'] ?? $data['json'] ?? $data['text'] ?? null;
        
        if ($conteudo) {
            if (is_string($conteudo) && (str_starts_with(trim($conteudo), '{') || str_starts_with(trim($conteudo), '['))) {
                return $this->extrairJsonDeString($conteudo);
            }
            if (is_array($conteudo)) {
                return $this->formatarResposta($conteudo);
            }
            if (is_string($conteudo)) {
                return ['mensagem' => $conteudo, 'dados_estruturados' => null];
            }
        }

        return $this->formatarResposta($data);
    }

    private function extrairJsonDeString(string $texto): array
    {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $texto, $matches)) {
            $json = json_decode(trim($matches[1]), true);
            if ($json) return $this->formatarResposta($json);
        }
        $json = json_decode($texto, true);
        if ($json) return $this->formatarResposta($json);

        return ['mensagem' => $texto, 'dados_estruturados' => null];
    }

    /**
     * Formata resposta detectando automaticamente o tipo de dados
     */
    private function formatarResposta($data): array
    {
        $dadosMapeados = [];
        $textoMensagem = null;
        $tipoDetectado = null;
        $colunas = [];

        if (is_array($data)) {
            // A. DETECTAR TIPO DE IMPORTAÇÃO
            $tipoDetectado = $this->detectarTipoImportacao($data);
            
            // B. EXTRAIR DADOS MAPEADOS
            if (array_is_list($data) && count($data) > 0 && is_array($data[0])) {
                $dadosMapeados = $data;
            } else {
                // Buscar em chaves conhecidas
                $chavesDados = [
                    'dados_mapeados', 'registros', 'data', 'items', 'rows', 
                    'clientes', 'servicos', 'funcionarios', 'empresas',
                    'lista', 'fatura', 'titulos', 'exames'
                ];
                
                foreach ($chavesDados as $k) {
                    if (isset($data[$k]) && is_array($data[$k]) && count($data[$k]) > 0) {
                        $dadosMapeados = $data[$k];
                        break;
                    }
                }

                // Verificar estrutura de empresa única
                if (empty($dadosMapeados) && isset($data['empresa'])) {
                    $dadosMapeados = [$data['empresa']];
                    $tipoDetectado = 'clientes';
                }
            }

            // C. DEFINIR COLUNAS BASEADO NO TIPO
            if (!empty($dadosMapeados)) {
                $colunas = $this->definirColunas($tipoDetectado, $dadosMapeados);
            }

            // D. EXTRAIR TEXTO
            $chavesTexto = ['mensagem', 'message', 'output', 'text', 'response', 'answer', 'content', 'reply', 'texto', 'body', 'resumo'];
            foreach ($chavesTexto as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    $textoMensagem = $data[$key];
                    break;
                }
            }
        }

        // E. RETORNO FINAL
        if (!empty($dadosMapeados)) {
            $acaoSugerida = $this->definirAcaoSugerida($tipoDetectado);
            
            return [
                'mensagem' => $textoMensagem ?? $this->gerarMensagemPreview($tipoDetectado, count($dadosMapeados)),
                'dados_estruturados' => [
                    'sucesso'           => true,
                    'tipo'              => $tipoDetectado,
                    'dados_mapeados'    => $dadosMapeados,
                    'colunas'           => $colunas,
                    'acao_sugerida'     => $acaoSugerida,
                    'total_registros'   => count($dadosMapeados),
                    'confianca'         => $data['confianca'] ?? 1.0,
                    'erros'             => $data['erros'] ?? [],
                    'avisos'            => $data['avisos'] ?? [],
                    'metadata'          => [
                        'empresa_nome'  => $data['empresa_nome'] ?? null,
                        'periodo'       => $data['periodo'] ?? null,
                        'total_valor'   => $data['total_valor'] ?? null,
                    ]
                ]
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

    /**
     * Detecta o tipo de importação baseado nos campos presentes
     */
    private function detectarTipoImportacao($data): ?string
    {
        // Verificar tipo explícito
        if (isset($data['tipo'])) {
            return $data['tipo'];
        }

        // Pegar amostra de dados
        $amostra = [];
        if (array_is_list($data) && !empty($data[0])) {
            $amostra = $data[0];
        } elseif (isset($data['dados_mapeados'][0])) {
            $amostra = $data['dados_mapeados'][0];
        } elseif (isset($data['empresa'])) {
            $amostra = $data['empresa'];
            return 'clientes';
        }

        if (empty($amostra)) return 'generico';

        $campos = array_keys($amostra);
        // Normalizar: minúsculas, remover espaços, acentos e underscores
        $camposStr = strtolower(implode(',', $campos));
        $camposStr = str_replace([' ', '_', '-'], '', $camposStr);
        $camposStr = $this->removerAcentos($camposStr);

        // Detectar por campos característicos
        // Clientes: CNPJ, Razão Social, Nome Fantasia
        if (str_contains($camposStr, 'cnpj') || 
            str_contains($camposStr, 'razaosocial') || 
            str_contains($camposStr, 'nomefantasia')) {
            return 'clientes';
        }

        // Funcionários/Fatura: matrícula, funcionário, setor
        if (str_contains($camposStr, 'matricula') || 
            str_contains($camposStr, 'funcionario') || 
            str_contains($camposStr, 'setor')) {
            if (str_contains($camposStr, 'exame') || str_contains($camposStr, 'tuss')) {
                return 'fatura_funcionarios';
            }
            return 'funcionarios';
        }

        // Fatura Títulos: produto, serviço, vidas
        if (str_contains($camposStr, 'produto') || 
            str_contains($camposStr, 'servico') || 
            str_contains($camposStr, 'vidas')) {
            return 'fatura_titulos';
        }

        // Serviços: código, descrição
        if (str_contains($camposStr, 'codigo') && str_contains($camposStr, 'descricao')) {
            return 'servicos';
        }

        return 'generico';
    }

    /**
     * Remove acentos de uma string
     */
    private function removerAcentos(string $string): string
    {
        return preg_replace(
            ['/[áàãâä]/u', '/[éèêë]/u', '/[íìîï]/u', '/[óòõôö]/u', '/[úùûü]/u', '/[ç]/u'],
            ['a', 'e', 'i', 'o', 'u', 'c'],
            $string
        );
    }

    /**
     * Define colunas para exibição na tabela
     */
    private function definirColunas(?string $tipo, array $dados): array
    {
        $primeiroRegistro = $dados[0] ?? [];
        $todasColunas = array_keys($primeiroRegistro);
        
        // Criar mapa normalizado das colunas reais
        $mapaColunasReais = [];
        foreach ($todasColunas as $coluna) {
            $normalizada = $this->normalizarNomeColuna($coluna);
            $mapaColunasReais[$normalizada] = $coluna;
        }

        // Colunas prioritárias por tipo
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
                ['key' => 'valor', 'label' => 'Valor (R$)', 'width' => 100, 'align' => 'right', 'format' => 'currency'],
            ],
            'fatura_titulos' => [
                ['key' => 'produtoservico', 'label' => 'Produto/Serviço', 'width' => 250],
                ['key' => 'datacobranca', 'label' => 'Data Cobrança', 'width' => 120],
                ['key' => 'vidasativas', 'label' => 'Vidas', 'width' => 80, 'align' => 'center'],
                ['key' => 'valorvida', 'label' => 'Valor/Vida', 'width' => 100, 'align' => 'right', 'format' => 'currency'],
                ['key' => 'total', 'label' => 'Total (R$)', 'width' => 120, 'align' => 'right', 'format' => 'currency'],
            ],
            'funcionarios' => [
                ['key' => 'nome', 'label' => 'Nome', 'width' => 200],
                ['key' => 'cpf', 'label' => 'CPF', 'width' => 120],
                ['key' => 'matricula', 'label' => 'Matrícula', 'width' => 100],
                ['key' => 'cargo', 'label' => 'Cargo', 'width' => 150],
                ['key' => 'setor', 'label' => 'Setor', 'width' => 120],
                ['key' => 'situacao', 'label' => 'Situação', 'width' => 80],
            ],
            'servicos' => [
                ['key' => 'codigo', 'label' => 'Código', 'width' => 100],
                ['key' => 'descricao', 'label' => 'Descrição', 'width' => 250],
                ['key' => 'valor', 'label' => 'Valor (R$)', 'width' => 100, 'align' => 'right', 'format' => 'currency'],
                ['key' => 'unidade', 'label' => 'Unidade', 'width' => 80],
            ],
            default => [] // Inclui null e 'generico'
        };

        // Se não há colunas preferidas, gerar automaticamente
        if (empty($colunasPreferidas)) {
            return array_map(fn($key) => [
                'key' => $key,
                'label' => $this->formatarLabelColuna($key),
                'width' => 150
            ], array_slice($todasColunas, 0, 8)); // Limitar a 8 colunas
        }

        // Mapear colunas preferidas para as colunas reais dos dados
        $colunasFinais = [];
        foreach ($colunasPreferidas as $col) {
            $keyNormalizada = $col['key'];
            
            // Verificar se existe uma coluna real correspondente
            if (isset($mapaColunasReais[$keyNormalizada])) {
                $col['key'] = $mapaColunasReais[$keyNormalizada]; // Usar nome real da coluna
                $colunasFinais[] = $col;
            }
        }

        // Se não encontrou nenhuma coluna preferida, usar colunas automáticas
        if (empty($colunasFinais)) {
            return array_map(fn($key) => [
                'key' => $key,
                'label' => $this->formatarLabelColuna($key),
                'width' => 150
            ], array_slice($todasColunas, 0, 8));
        }

        return $colunasFinais;
    }
    
    /**
     * Normaliza nome de coluna para comparação
     */
    private function normalizarNomeColuna(string $nome): string
    {
        $nome = strtolower($nome);
        $nome = str_replace([' ', '_', '-', '.'], '', $nome);
        $nome = $this->removerAcentos($nome);
        return $nome;
    }

    private function formatarLabelColuna(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    private function definirAcaoSugerida(?string $tipo): string
    {
        return match($tipo) {
            'clientes' => 'importar_clientes',
            'funcionarios' => 'importar_funcionarios',
            'fatura_titulos' => 'gerar_fatura',
            'fatura_funcionarios' => 'gerar_fatura',
            'servicos' => 'importar_servicos',
            default => 'importar_generico'
        };
    }

    private function gerarMensagemPreview(?string $tipo, int $total): string
    {
        return match($tipo) {
            'clientes' => "✅ Encontrei **{$total} cliente(s)** para importação. Verifique os dados na tabela e confirme.",
            'funcionarios' => "✅ Encontrei **{$total} funcionário(s)**. Revise os dados antes de importar.",
            'fatura_titulos' => "📋 Processada fatura com **{$total} título(s)**. Confira os valores abaixo.",
            'fatura_funcionarios' => "📋 Encontrei **{$total} registro(s) de exames/funcionários** na fatura.",
            'servicos' => "✅ Encontrei **{$total} serviço(s)** para importação.",
            default => "✅ Processados **{$total} registro(s)**. Verifique os dados na tabela."
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

    /**
     * Confirmar ação de importação
     */
    public function confirmarAcao(Request $request)
    {
        // Aceita 'acao' ou 'tipo' para compatibilidade
        $acao = $request->input('acao') ?? $request->input('tipo');
        $dados = $request->input('dados', []);
        $metadata = $request->input('metadata', []);
        
        if (empty($dados)) {
            return response()->json(['success' => false, 'message' => 'Nenhum dado para importar.'], 422);
        }

        DB::beginTransaction();
        try {
            $resultado = match($acao) {
                // Clientes
                'importar_clientes', 'clientes' => $this->importarClientes($dados),
                
                // Funcionários
                'importar_funcionarios', 'funcionarios' => $this->importarFuncionarios($dados),
                
                // Serviços
                'importar_servicos', 'servicos' => $this->importarServicos($dados),
                
                // Fatura/OS - Relatório de Fatura do SOC
                'gerar_fatura', 'fatura', 'fatura_titulos' => $this->gerarOrdemServico($dados, $metadata),
                'fatura_funcionarios', 'importar_fatura', 'relatorio_fatura' => $this->gerarOrdemServicoDetalhada($dados, $metadata),
                
                // Genérico
                default => $this->importarGenerico($acao, $dados)
            };

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => $resultado['message'],
                'detalhes' => $resultado['detalhes'] ?? null
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao confirmar ação: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['success' => false, 'message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }

    private function importarClientes(array $dados): array
    {
        $criados = 0;
        $atualizados = 0;

        foreach ($dados as $d) {
            $cnpj = preg_replace('/\D/', '', $this->getValorFlexivel($d, 'cnpj') ?? '');
            if (empty($cnpj)) continue;

            $cliente = Cliente::where('cnpj', $cnpj)->first();
            
            $dadosCliente = [
                'razao_social'  => $this->getValorFlexivel($d, 'razao_social', 'razaosocial', 'RAZAO SOCIAL'),
                'nome_fantasia' => $this->getValorFlexivel($d, 'nome_fantasia', 'nomefantasia', 'NOME FANTASIA'),
                'cnpj'          => $cnpj,
                'email'         => $this->getValorFlexivel($d, 'email', 'EMAIL'),
                'telefone'      => $this->getValorFlexivel($d, 'telefone', 'TELEFONE'),
                'celular'       => $this->getValorFlexivel($d, 'celular', 'CELULAR'),
                'cep'           => preg_replace('/\D/', '', $this->getValorFlexivel($d, 'cep', 'CEP') ?? ''),
                'logradouro'    => $this->getValorFlexivel($d, 'logradouro', 'LOGRADOURO'),
                'numero'        => $this->getValorFlexivel($d, 'numero', 'NUMERO'),
                'bairro'        => $this->getValorFlexivel($d, 'bairro', 'BAIRRO'),
                'cidade'        => $this->getValorFlexivel($d, 'cidade', 'CIDADE'),
                'uf'            => $this->getValorFlexivel($d, 'uf', 'UF'),
                'status'        => 'ativo',
            ];

            if ($cliente) {
                $cliente->update($dadosCliente);
                $atualizados++;
            } else {
                Cliente::create($dadosCliente);
                $criados++;
            }
        }

        return [
            'message' => "✅ Clientes importados: {$criados} novos, {$atualizados} atualizados.",
            'detalhes' => compact('criados', 'atualizados')
        ];
    }

    private function importarFuncionarios(array $dados): array
    {
        $total = 0;
        foreach ($dados as $d) {
            Funcionario::updateOrCreate(
                ['cpf' => preg_replace('/\D/', '', $d['cpf'] ?? '')],
                [
                    'nome'      => $d['nome'] ?? null,
                    'matricula' => $d['matricula'] ?? null,
                    'cargo'     => $d['cargo'] ?? null,
                    'setor'     => $d['setor'] ?? null,
                    'situacao'  => $d['situacao'] ?? 'Ativo',
                ]
            );
            $total++;
        }
        return ['message' => "✅ {$total} funcionário(s) importado(s)."];
    }

    private function importarServicos(array $dados): array
    {
        $total = 0;
        foreach ($dados as $d) {
            Servico::updateOrCreate(
                ['codigo' => $d['codigo'] ?? null],
                [
                    'descricao' => $d['descricao'] ?? null,
                    'valor'     => $this->parseValor($d['valor'] ?? 0),
                    'unidade'   => $d['unidade'] ?? null,
                ]
            );
            $total++;
        }
        return ['message' => "✅ {$total} serviço(s) importado(s)."];
    }

    /**
     * Gerar Ordem de Serviço a partir de títulos de fatura (resumo)
     * Usado para: fatura_titulos, gerar_fatura
     */
    private function gerarOrdemServico(array $dados, array $metadata): array
    {
        // Identificar ou criar cliente
        $clienteId = $metadata['cliente_id'] ?? null;
        $empresaNome = $metadata['empresa_nome'] ?? null;
        
        if (!$clienteId && $empresaNome) {
            // Tenta encontrar cliente pelo nome
            $cliente = Cliente::where('razao_social', 'like', "%{$empresaNome}%")
                ->orWhere('nome_fantasia', 'like', "%{$empresaNome}%")
                ->first();
            $clienteId = $cliente?->id;
        }

        // Calcular valor total
        $valorTotal = 0;
        foreach ($dados as $item) {
            $valorTotal += $this->parseValor(
                $this->getValorFlexivel($item, 'total', 'Total R$', 'valor_total', 'valor') ?? 0
            );
        }

        // Gerar código único
        $codigoOs = 'OS-CHAT-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Determinar competência
        $competencia = $metadata['periodo'] ?? now()->format('m/Y');

        // Criar Ordem de Serviço
        $os = OrdemServico::create([
            'cliente_id'    => $clienteId,
            'codigo_os'     => $codigoOs,
            'competencia'   => $competencia,
            'data_emissao'  => now(),
            'valor_total'   => $valorTotal,
            'status'        => 'pendente',
            'observacoes'   => "Importado via Chat IA - " . ($empresaNome ?? 'Cliente não identificado'),
        ]);

        // Criar itens da OS
        $rateio = [];
        foreach ($dados as $item) {
            $descricao = $this->getValorFlexivel($item, 'produto_servico', 'Produto/Serviço', 'descricao', 'servico');
            $quantidade = (int) ($this->getValorFlexivel($item, 'vidas_ativas', 'Vidas Ativas', 'quantidade') ?? 1);
            $valorUnitario = $this->parseValor($this->getValorFlexivel($item, 'valor_vida', 'Valor por Vida R$', 'valor_unitario') ?? 0);
            $valorTotalItem = $this->parseValor($this->getValorFlexivel($item, 'total', 'Total R$', 'valor_total') ?? 0);
            
            // Se não tem valor total mas tem unitário e quantidade
            if ($valorTotalItem == 0 && $valorUnitario > 0) {
                $valorTotalItem = $valorUnitario * $quantidade;
            }

            $centroCusto = $this->getValorFlexivel($item, 'centro_custo', 'setor', 'unidade') ?? 'Geral';

            OrdemServicoItem::create([
                'ordem_servico_id' => $os->id,
                'descricao'        => $descricao ?? 'Serviço',
                'quantidade'       => $quantidade ?: 1,
                'valor_unitario'   => $valorUnitario,
                'valor_total'      => $valorTotalItem,
                'centro_custo'     => $centroCusto,
                'data_realizacao'  => $this->getValorFlexivel($item, 'data_cobranca', 'Data Cobrança') ?? now()->format('Y-m-d'),
            ]);

            // Acumular rateio por centro de custo
            if (!isset($rateio[$centroCusto])) {
                $rateio[$centroCusto] = 0;
            }
            $rateio[$centroCusto] += $valorTotalItem;
        }

        // Criar rateios
        foreach ($rateio as $cc => $valor) {
            OrdemServicoRateio::create([
                'ordem_servico_id' => $os->id,
                'centro_custo'     => $cc,
                'valor'            => $valor,
                'percentual'       => $valorTotal > 0 ? round(($valor / $valorTotal) * 100, 2) : 0,
            ]);
        }

        return [
            'message' => "✅ Ordem de Serviço #{$os->codigo_os} criada com " . count($dados) . " item(ns). Total: R$ " . number_format($valorTotal, 2, ',', '.'),
            'detalhes' => [
                'os_id' => $os->id,
                'codigo_os' => $os->codigo_os,
                'valor_total' => $valorTotal,
                'itens' => count($dados),
                'rateio' => $rateio
            ]
        ];
    }

    /**
     * Gerar Ordem de Serviço a partir de detalhes de funcionários/exames
     * Usado para: fatura_funcionarios, relatorio_fatura
     */
    private function gerarOrdemServicoDetalhada(array $dados, array $metadata): array
    {
        // Identificar ou criar cliente
        $clienteId = $metadata['cliente_id'] ?? null;
        $empresaNome = $metadata['empresa_nome'] ?? null;
        
        if (!$clienteId && $empresaNome) {
            $cliente = Cliente::where('razao_social', 'like', "%{$empresaNome}%")
                ->orWhere('nome_fantasia', 'like', "%{$empresaNome}%")
                ->first();
            $clienteId = $cliente?->id;
        }

        // Agrupar itens por tipo de exame e calcular totais
        $itensPorExame = [];
        $valorTotal = 0;
        $rateio = [];

        foreach ($dados as $item) {
            $exame = $this->getValorFlexivel($item, 'exame', 'Exame', 'descricao', 'servico') ?? 'Serviço';
            $valor = $this->parseValor($this->getValorFlexivel($item, 'valor', 'Vl.Cobrar R$', 'valor_cobrar', 'total') ?? 0);
            $setor = $this->getValorFlexivel($item, 'setor', 'Setor', 'centro_custo') ?? 'Geral';
            $funcionario = $this->getValorFlexivel($item, 'nome', 'Nome', 'funcionario');
            $tipoExame = $this->getValorFlexivel($item, 'tipo', 'Tipo') ?? '';

            // Agrupar por exame
            $chaveExame = $exame . '|' . $tipoExame;
            if (!isset($itensPorExame[$chaveExame])) {
                $itensPorExame[$chaveExame] = [
                    'descricao' => $exame . ($tipoExame ? " ({$tipoExame})" : ''),
                    'quantidade' => 0,
                    'valor_total' => 0,
                    'funcionarios' => []
                ];
            }
            
            $itensPorExame[$chaveExame]['quantidade']++;
            $itensPorExame[$chaveExame]['valor_total'] += $valor;
            if ($funcionario) {
                $itensPorExame[$chaveExame]['funcionarios'][] = $funcionario;
            }

            $valorTotal += $valor;

            // Rateio por setor
            if (!isset($rateio[$setor])) {
                $rateio[$setor] = 0;
            }
            $rateio[$setor] += $valor;
        }

        // Gerar código único
        $codigoOs = 'OS-CHAT-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $competencia = $metadata['periodo'] ?? now()->format('m/Y');

        // Criar Ordem de Serviço
        $os = OrdemServico::create([
            'cliente_id'    => $clienteId,
            'codigo_os'     => $codigoOs,
            'competencia'   => $competencia,
            'data_emissao'  => now(),
            'valor_total'   => $valorTotal,
            'status'        => 'pendente',
            'observacoes'   => "Importado via Chat IA (Detalhado) - " . count($dados) . " registros de funcionários/exames",
        ]);

        // Criar itens agrupados
        foreach ($itensPorExame as $item) {
            if ($item['valor_total'] > 0 || $item['quantidade'] > 0) {
                $valorUnitario = $item['quantidade'] > 0 ? $item['valor_total'] / $item['quantidade'] : 0;
                
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

        // Criar rateios
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

        return [
            'message' => "✅ Ordem de Serviço #{$os->codigo_os} criada com {$totalExames} exame(s) em " . count($itensPorExame) . " tipo(s). Total: R$ " . number_format($valorTotal, 2, ',', '.'),
            'detalhes' => [
                'os_id' => $os->id,
                'codigo_os' => $os->codigo_os,
                'valor_total' => $valorTotal,
                'total_exames' => $totalExames,
                'tipos_exame' => count($itensPorExame),
                'rateio' => $rateio
            ]
        ];
    }

    private function importarGenerico(?string $acao, array $dados): array
    {
        return ['message' => "✅ " . count($dados) . " registro(s) processado(s) para: " . ($acao ?? 'genérico')];
    }

    /**
     * Obtém valor de um array tentando múltiplas variações de chave
     */
    private function getValorFlexivel(array $dados, string ...$chaves): mixed
    {
        foreach ($chaves as $chave) {
            // Tenta chave exata
            if (isset($dados[$chave])) {
                return $dados[$chave];
            }
        }
        
        // Normaliza todas as chaves do array
        $dadosNormalizados = [];
        foreach ($dados as $key => $value) {
            $keyNormalizada = $this->normalizarNomeColuna($key);
            $dadosNormalizados[$keyNormalizada] = $value;
        }
        
        // Tenta encontrar a primeira chave que bate
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
        if (is_numeric($valor)) return (float) $valor;
        
        // Tratar formato brasileiro: 1.234,56
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
        $valor = preg_replace('/[^\d.]/', '', $valor);
        
        return (float) $valor;
    }
}