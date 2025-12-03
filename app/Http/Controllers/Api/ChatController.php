<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\OrdemServicoRateio;
use App\Models\Fatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class ChatController extends Controller
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB limit
    private array $cacheColunasClientes = [];

    /**
     * =========================================================================
     * 1. FLUXO DE CHAT (ENVIO DE MENSAGEM PARA N8N)
     * =========================================================================
     */
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

            // 1.1 Processamento e Validação de Arquivo
            if ($request->hasFile('arquivo') && $request->file('arquivo')->isValid()) {
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

            // Validação: Não pode enviar nada vazio
            if ($mensagem === '' && !$arquivoData) {
                return response()->json(['success' => false, 'message' => 'Envie uma mensagem ou arquivo.'], 422);
            }

            // 1.2 Persistência da Mensagem do Usuário
            $conteudoLog = $arquivoData ? "[Arquivo: {$arquivoData['nome']}] " . $mensagem : $mensagem;
            
            ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'user',
                'content'    => $conteudoLog,
                'session_id' => $sessionId,
                'metadata'   => $arquivoData ? ['file_name' => $arquivoData['nome']] : null
            ]);

            // Respostas rápidas locais (consultas simples a clientes/faturas/OS)
            $respostaRapida = $this->responderLocalmente($mensagem);
            if ($respostaRapida) {
                $chatMessage = ChatMessage::create([
                    'user_id'    => $user->id,
                    'role'       => 'assistant',
                    'content'    => $respostaRapida,
                    'session_id' => $sessionId,
                    'metadata'   => null
                ]);

                return response()->json([
                    'success'            => true,
                    'id'                 => $chatMessage->id,
                    'role'               => $chatMessage->role,
                    'content'            => $chatMessage->content,
                    'created_at'         => $chatMessage->created_at->toISOString(),
                    'dados_estruturados' => null,
                    'acao_sugerida'      => null,
                ]);
            }

            // 1.3 Envio para N8N (Core Logic)
            $respostaIa = $this->conectarComN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento);

            // 1.4 Persistência da Resposta da IA
            $chatMessage = ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'assistant',
                'content'    => $respostaIa['mensagem'], 
                'session_id' => $sessionId,
                'metadata'   => $respostaIa['dados_estruturados'] ?? null
            ]);

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
            Log::error('Erro ChatController@enviarMensagem: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Comunicação HTTP com o N8N
     */
private function conectarComN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento): array
    {
        // Define Rota: Se tem arquivo, vai para rota de arquivo. Se não, rota de chat.
        $isArquivo = !empty($arquivoData);
        $webhookUrl = $isArquivo ? env('N8N_WEBHOOK_URL') : env('N8N_WEBHOOK_CHAT_URL');
        $timeout = $isArquivo ? 600 : 120; // Mais tempo para arquivos
        $rotaNome = $isArquivo ? "ARQUIVO" : "CHAT";

        Log::info("📡 Enviando para N8N [{$rotaNome}]", ['url' => $webhookUrl]);

        if (!$webhookUrl) {
            return ['mensagem' => "⚠️ Erro de Configuração: Webhook {$rotaNome} não definido no .env.", 'dados_estruturados' => null];
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

            if ($isArquivo) {
                $payload['arquivo'] = $arquivoData;
            }

            $response = Http::timeout($timeout)->post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::error("❌ Erro N8N {$rotaNome}: " . $response->body());
                return ['mensagem' => "❌ Erro ao processar (HTTP {$response->status()}).", 'dados_estruturados' => null];
            }

            // Processa o retorno JSON
            $body = $response->json() ?? json_decode($response->body(), true);

            $normalizado = $this->normalizarRespostaN8n($body);

            return [
                'mensagem'           => $normalizado['mensagem'] ?? json_encode($body),
                'dados_estruturados' => $normalizado['dados_estruturados'] ?? null,
                'acao_sugerida'      => $normalizado['acao_sugerida'] ?? ($normalizado['dados_estruturados']['acao_sugerida'] ?? null)
            ];

        } catch (\Exception $e) {
            Log::error('Exceção N8N: ' . $e->getMessage());
            return ['mensagem' => '❌ Falha de conexão com IA.', 'dados_estruturados' => null];
        }
    }

    /**
     * =========================================================================
     * 2. CONFIRMAÇÃO DE AÇÃO (IMPORTAÇÃO E CADASTROS)
     * =========================================================================
     */
   public function confirmarAcao(Request $request)
{
    $inputDados = $request->input('dados', []);
    $acao = $request->input('acao') ?? $request->input('tipo');
    $metadata = $request->input('metadata', []);

    // 2.1 PASSO CRÍTICO: Extrair a lista real de dentro do JSON complexo
    $dadosParaProcessar = $this->extrairDadosParaProcessamento($inputDados);

    if (empty($dadosParaProcessar)) {
        Log::warning("⚠️ Tentativa de importação sem dados válidos.", [
            'input_bruto' => $inputDados
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Nenhum registro encontrado para processar.'
        ], 422);
    }

    // 2.2 Auto-detecção de ação se não vier especificada
    if (empty($acao) || $acao === 'generico') {
        $acao = $this->detectarAcaoPelosDados($dadosParaProcessar);
    }
    // Se o front mandou importar_clientes mas o payload é de OS, força gerar_os
    if ($acao === 'importar_clientes' && $this->pareceOrdemServico($dadosParaProcessar)) {
        $acao = 'gerar_os';
    }

    Log::info("📥 Iniciando Ação: '{$acao}' | Qtd Registros: " . count($dadosParaProcessar), [
        'metadata' => $metadata,
    ]);

    DB::beginTransaction();

    try {
        $resultado = match ($acao) {
            // Clientes
            'importar_clientes', 'clientes', 'cadastro_cliente'
                => $this->processarImportacaoClientes($dadosParaProcessar),

            // Ordens de serviço
            'gerar_os', 'ordem_servico'
                => $this->processarImportacaoOrdensServico($dadosParaProcessar),

            default
                => $this->processarImportacaoClientes($dadosParaProcessar), // fallback seguro
        };

        DB::commit();

        // Se houve erros de linha na importação, NÃO considera sucesso total
        $detalhes = $resultado['detalhes'] ?? null;
        $success  = $this->avaliarSucessoImportacao($detalhes, $acao);

        return response()->json([
            'success'  => $success,
            'message'  => $resultado['message'] ?? '',
            'detalhes' => $detalhes,
        ]);

    } catch (\Exception $e) {
        DB::rollBack();

        Log::error("❌ Erro fatal ao confirmar ação: " . $e->getMessage(), [
            'acao'     => $acao,
            'metadata' => $metadata,
            'trace'    => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erro: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * =========================================================================
     * 3. LÓGICA DE IMPORTAÇÃO DE CLIENTES
     * =========================================================================
     */
   private function processarImportacaoClientes(array $listaClientes): array
    {
        $stats = ['novos' => 0, 'atualizados' => 0, 'erros' => 0];
        $errosDetalhados = [];
        $colunasTabelaFlip = $this->obterColunasClientes();

        // Pré-carrega clientes para reduzir queries (por CNPJ e Razão Social)
        $listaClientes = array_values($listaClientes);
        $cnpjsBusca = [];
        $razoesBusca = [];
        foreach ($listaClientes as $row) {
            if (!is_array($row)) continue;
            $cnpjRaw = $this->buscarValorFlexivel($row, 'cnpj', 'cpf', 'doc');
            $cnpj = $cnpjRaw ? preg_replace('/\D/', '', (string) $cnpjRaw) : null;
            if ($cnpj && strlen($cnpj) >= 11) {
                $cnpjsBusca[] = $cnpj;
            }
            $razaoSocial = $this->buscarValorFlexivel($row, 'razao_social', 'nome', 'empresa', 'cliente');
            if ($razaoSocial) {
                $razoesBusca[] = mb_strtoupper($razaoSocial);
            }
        }

        $clientesMapaCnpj = [];
        $clientesMapaRazao = [];
        if (!empty($cnpjsBusca) || !empty($razoesBusca)) {
            $clientes = Cliente::withTrashed()
                ->where(function ($q) use ($cnpjsBusca, $razoesBusca) {
                    if (!empty($cnpjsBusca)) {
                        $q->whereIn('cnpj', $cnpjsBusca);
                    }
                    if (!empty($razoesBusca)) {
                        $q->orWhereIn(DB::raw('upper(razao_social)'), $razoesBusca);
                    }
                })
                ->get();

            foreach ($clientes as $cli) {
                if ($cli->cnpj) {
                    $clientesMapaCnpj[$cli->cnpj] = $cli;
                }
                $clientesMapaRazao[mb_strtoupper($cli->razao_social)] = $cli;
            }
        }

        foreach ($listaClientes as $index => $row) {
            if (!is_array($row)) continue;

            try {
                $cnpjRaw = $this->buscarValorFlexivel($row, 'cnpj', 'cpf', 'doc');
                $cnpj = preg_replace('/\D/', '', (string) $cnpjRaw);

                $razaoSocial = $this->buscarValorFlexivel($row, 'razao_social', 'nome', 'empresa', 'cliente');
                
                if (empty($razaoSocial)) {
                     throw new \Exception("Razão Social obrigatória.");
                }
                if (empty($cnpj)) {
                    throw new \Exception("CNPJ/CPF obrigatório para importar cliente.");
                }

                $clienteData = [
                    'cnpj'          => (strlen($cnpj) >= 11) ? $cnpj : null,
                    'razao_social'  => mb_strtoupper($razaoSocial),
                    'nome_fantasia' => mb_strtoupper($this->buscarValorFlexivel($row, 'nome_fantasia', 'fantasia') ?? ''),
                    'email'         => strtolower($this->buscarValorFlexivel($row, 'email', 'e-mail') ?? ''),
                    'telefone'      => $this->buscarValorFlexivel($row, 'telefone', 'tel', 'fixo'),
                    'celular'       => $this->buscarValorFlexivel($row, 'celular', 'cel', 'whatsapp'),
                    'cep'           => preg_replace('/\D/', '', $this->buscarValorFlexivel($row, 'cep') ?? ''),
                    'logradouro'    => $this->buscarValorFlexivel($row, 'logradouro', 'endereco', 'rua'),
                    'numero'        => $this->buscarValorFlexivel($row, 'numero', 'num'),
                    'bairro'        => $this->buscarValorFlexivel($row, 'bairro'),
                    'cidade'        => $this->buscarValorFlexivel($row, 'cidade', 'municipio'),
                    'uf'            => strtoupper($this->buscarValorFlexivel($row, 'uf', 'estado') ?? ''),
                    'status'        => 'ativo'
                ];

                // Ajuste de coluna: se a base não tem "logradouro", usa "endereco"
                if (!isset($colunasTabelaFlip['logradouro']) && isset($colunasTabelaFlip['endereco'])) {
                    $clienteData['endereco'] = $clienteData['logradouro'] ?? null;
                    unset($clienteData['logradouro']);
                }

                // Remove campos que não existem na tabela para evitar erro de coluna inexistente
                $clienteData = array_intersect_key($clienteData, $colunasTabelaFlip);

                // Upsert Logic
                $cliente = null;
                if (!empty($clienteData['cnpj'])) {
                    $cliente = $clientesMapaCnpj[$clienteData['cnpj']] ?? null;
                }
                
                // Fallback para nome se não tiver CNPJ (cuidado com homônimos, mas útil para importação suja)
                if (!$cliente && empty($clienteData['cnpj'])) {
                    $cliente = $clientesMapaRazao[$clienteData['razao_social']] ?? null;
                }

                if ($cliente) {
                    if ($cliente->trashed()) {
                        $cliente->restore();
                    }
                    $cliente->update($clienteData);
                    $stats['atualizados']++;
                } else {
                    Cliente::create($clienteData);
                    $stats['novos']++;
                }

            } catch (\Exception $e) {
                $stats['erros']++;
                $errosDetalhados[] = "Linha " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        $msg = "✅ {$stats['novos']} novos, {$stats['atualizados']} atualizados.";
        if ($stats['erros'] > 0) $msg .= " ({$stats['erros']} erros).";

        return [
            'message' => $msg,
            'detalhes' => [
                'resumo' => $stats,
                'erros_lista' => array_slice($errosDetalhados, 0, 5)
            ]
        ];
    }

    /**
     * =========================================================================
     * 3.2 IMPORTAÇÃO DE ORDENS DE SERVIÇO (GERADAS PELO RELATÓRIO DE FATURA)
     * =========================================================================
     */
    private function processarImportacaoOrdensServico(array $listaOs): array
    {
        $stats = ['criados' => 0, 'erros' => 0];
        $errosDetalhados = [];

        foreach ($listaOs as $index => $row) {
            if (!is_array($row)) continue;

            DB::beginTransaction();

            try {
                $empresa = $this->buscarValorFlexivel($row, 'empresa', 'cliente', 'razao_social', 'nome');
                $cnpjRaw = $this->buscarValorFlexivel($row, 'cnpj', 'doc');
                $cnpj = $cnpjRaw ? preg_replace('/\D/', '', $cnpjRaw) : null;

                if (empty($empresa) && empty($cnpj)) {
                    throw new \Exception("Empresa/cliente não informado.");
                }

                $cliente = $this->buscarOuCriarClienteParaOs($empresa, $cnpj);
                if (!$cliente || !$cliente->id) {
                    throw new \Exception("Cliente não encontrado ou não pôde ser criado.");
                }

                $titulos = $row['titulos'] ?? ($row['raw']['titulos'] ?? []);
                if (!is_array($titulos) || empty($titulos)) {
                    throw new \Exception("Nenhum título encontrado para gerar OS.");
                }

                $dataCobrancaStr = $titulos[0]['data_cobranca'] ?? ($row['data_cobranca'] ?? null);
                $dataCobranca = $this->parseDataBr($dataCobrancaStr);
                $competencia = $dataCobranca ? $dataCobranca->format('m/Y') : now()->format('m/Y');

                $os = OrdemServico::create([
                    'cliente_id'  => $cliente->id,
                    'codigo_os'   => 'OS-' . date('Ymd-His') . '-' . ($index + 1) . '-' . uniqid(),
                    'competencia' => $competencia,
                    'data_emissao'=> $dataCobranca ? $dataCobranca->toDateString() : now()->toDateString(),
                    'status'      => 'pendente',
                    'valor_total' => 0,
                    'observacoes' => 'Gerado via chat/N8N',
                ]);
                if (!$os || !$os->id) {
                    throw new \Exception("Falha ao criar ordem de serviço (sem ID).");
                }

                $valorTotal = 0;
                $rateios = [];

                foreach ($titulos as $titulo) {
                    if (!is_array($titulo)) continue;

                    $descricao = $this->buscarValorFlexivel($titulo, 'produto_servico', 'descricao', 'servico') ?? 'SERVICO';
                    if (!$descricao) $descricao = 'SERVICO';
                    $quantidade = (int) ($this->buscarValorFlexivel($titulo, 'vidas_ativas', 'quantidade') ?? 0);
                    if ($quantidade <= 0) $quantidade = 1;

                    $valorUnit = $this->parseMoneyString(
                        $this->buscarValorFlexivel($titulo, 'valor_por_vida', 'valor_por_vida_r$', 'valor_por_vida_R$', 'valor', 'valor_unitario') ?? 0
                    );
                    $valorUnit = $valorUnit > 0 ? $valorUnit : 0.0;

                    $valorTotalItem = $this->parseMoneyString(
                        $this->buscarValorFlexivel($titulo, 'total', 'total_r$', 'total_R$', 'valor_total') ?? null
                    );
                    if ($valorTotalItem <= 0) {
                        $valorTotalItem = $quantidade * $valorUnit;
                    }
                    if ($valorTotalItem < 0) $valorTotalItem = 0.0;

                    $valorTotal += $valorTotalItem;

                    OrdemServicoItem::create([
                        'ordem_servico_id'    => $os->id,
                        'descricao'           => $descricao,
                        'quantidade'          => $quantidade,
                        'valor_unitario'      => $valorUnit,
                        'valor_total'         => $valorTotalItem,
                        'unidade_soc'         => $titulo['unidade'] ?? null,
                        'funcionario_soc'     => $titulo['gerente_da_conta'] ?? null,
                        'centro_custo_cliente'=> $titulo['centro_custo'] ?? null,
                        'centro_custo'        => $titulo['centro_custo'] ?? ($titulo['produto_servico'] ?? null),
                    ]);

                    // Rateio por produto/serviço
                    $cc = $titulo['centro_custo'] ?? ($titulo['produto_servico'] ?? 'SERVICO');
                    if (!isset($rateios[$cc])) {
                        $rateios[$cc] = 0;
                    }
                    $rateios[$cc] += $valorTotalItem;
                }

                // Itens/exames detalhados (quando vierem em outro array)
                $exames = $row['exames'] ?? ($row['raw']['exames'] ?? []);
                if (is_array($exames)) {
                    foreach ($exames as $exame) {
                        if (!is_array($exame)) continue;
                        $descricao = $this->buscarValorFlexivel($exame, 'nome', 'exame', 'descricao') ?? 'EXAME';
                        if (!$descricao) $descricao = 'EXAME';
                        $quantidade = (int) ($this->buscarValorFlexivel($exame, 'quantidade', 'qtd') ?? 0);
                        if ($quantidade <= 0) $quantidade = 1;
                        $valorUnit = $this->parseMoneyString(
                            $this->buscarValorFlexivel($exame, 'valor_cobrar', 'valor_cobrar_r$', 'valor', 'valor_unitario') ?? 0
                        );
                        $valorUnit = $valorUnit > 0 ? $valorUnit : 0.0;
                        $valorTotalItem = $quantidade * $valorUnit;
                        if ($valorTotalItem < 0) $valorTotalItem = 0.0;
                        $valorTotal += $valorTotalItem;

                        OrdemServicoItem::create([
                            'ordem_servico_id' => $os->id,
                            'descricao'        => $descricao,
                            'quantidade'       => $quantidade,
                            'valor_unitario'   => $valorUnit,
                            'valor_total'      => $valorTotalItem,
                            'centro_custo'     => 'EXAMES',
                        ]);

                        if (!isset($rateios['EXAMES'])) {
                            $rateios['EXAMES'] = 0;
                        }
                        $rateios['EXAMES'] += $valorTotalItem;
                    }
                }

                $os->valor_total = $valorTotal;
                $os->save();

                // Salva rateios
                if ($valorTotal > 0 && !empty($rateios)) {
                    foreach ($rateios as $cc => $val) {
                        OrdemServicoRateio::create([
                            'ordem_servico_id' => $os->id,
                            'centro_custo'     => $cc,
                            'valor'            => $val,
                            'percentual'       => $valorTotal > 0 ? ($val / $valorTotal) * 100 : 0,
                        ]);
                    }
                }

                DB::commit();
                $stats['criados']++;

            } catch (\Exception $e) {
                DB::rollBack();
                $stats['erros']++;
                $msgErro = $this->formatarErroBd($e);
                $errosDetalhados[] = "Linha " . ($index + 1) . ": " . $msgErro;
                Log::error('Erro ao importar OS', [
                    'linha' => $index + 1,
                    'erro' => $msgErro,
                    'trace' => $e->getTraceAsString(),
                    'row' => $row,
                    'debug' => $this->extrairDebugQuery($e),
                ]);
            }
        }

        $msg = "✅ {$stats['criados']} ordens geradas.";
        if ($stats['erros'] > 0) $msg .= " ({$stats['erros']} erros).";

        return [
            'message' => $msg,
            'detalhes' => [
                'resumo' => $stats,
                'erros_lista' => array_slice($errosDetalhados, 0, 5)
            ]
        ];
    }

    private function formatarErroBd(\Exception $e): string
    {
        if ($e instanceof QueryException) {
            $detalhe = $e->errorInfo[2] ?? $e->getMessage();
            // Extrai coluna se vier na mensagem
            if (preg_match("/Column '([^']+)'/", $detalhe, $m)) {
                return "Coluna '{$m[1]}' não pode ser nula ou é inválida. ({$detalhe})";
            }

            // Fallback completo para facilitar debug
            $info = implode(' | ', array_filter($e->errorInfo ?? []));
            $sql  = method_exists($e, 'getSql') ? $e->getSql() : '';
            $bind = method_exists($e, 'getBindings') ? json_encode($e->getBindings()) : '';

            return trim($detalhe . " | info: {$info} | sql: {$sql} | bind: {$bind}");
        }
        return $e->getMessage();
    }

    /**
     * Extrai detalhes adicionais da QueryException para debug (SQL e bindings).
     */
    private function extrairDebugQuery(\Exception $e): ?array
    {
        if (!($e instanceof QueryException)) return null;

        return [
            'sqlstate'  => $e->errorInfo[0] ?? null,
            'code'      => $e->errorInfo[1] ?? null,
            'message'   => $e->errorInfo[2] ?? $e->getMessage(),
            'sql'       => method_exists($e, 'getSql') ? $e->getSql() : null,
            'bindings'  => method_exists($e, 'getBindings') ? $e->getBindings() : null,
        ];
    }

    /**
     * =========================================================================
     * 4. HELPERS E UTILITÁRIOS (O "CÉREBRO" DA EXTRAÇÃO)
     * =========================================================================
     */

    /**
     * Extrai a lista de registros de dentro da estrutura complexa do N8N
     */
    private function extrairDadosParaProcessamento($dados)
    {
        // Estruturas do N8N no formato [{ output: [...] }]
        if (is_array($dados)) {
            if (isset($dados['output']) && is_array($dados['output'])) {
                // Se output for objeto com chave de clientes/empresas, extrai a lista interna
                if ($this->contemListaClientes($dados['output'])) {
                    return $this->pegarListaClientesDeContainer($dados['output']);
                }
                if ($this->pareceOrdemServico($dados['output'])) {
                    return [$dados['output']];
                }
                return $dados['output'];
            }

            if (array_is_list($dados) && !empty($dados) && isset($dados[0]['output']) && is_array($dados[0]['output'])) {
                if ($this->contemListaClientes($dados[0]['output'])) {
                    return $this->pegarListaClientesDeContainer($dados[0]['output']);
                }
                if ($this->pareceOrdemServico($dados[0]['output'])) {
                    return [$dados[0]['output']];
                }
                return $dados[0]['output'];
            }
        }

        // Se já for lista limpa
        if (is_array($dados) && array_is_list($dados) && !empty($dados) && isset($dados[0]) && is_array($dados[0])) {
            return $dados;
        }

        // Chaves comuns do N8N ou do seu JSON
        $chaves = ['dados_mapeados', 'empresas', 'clientes', 'registros', 'data', 'output'];

        foreach ($chaves as $chave) {
            if (isset($dados[$chave]) && is_array($dados[$chave])) {
                return $dados[$chave];
            }
        }

        // Se for um único objeto de OS, envelopa em array
        if (is_array($dados) && $this->pareceOrdemServico($dados)) {
            return [$dados];
        }
        return [];
    }

    /**
     * Busca um valor dentro de um array usando múltiplas possibilidades de chaves
     * Ex: buscarValorFlexivel($row, 'razao_social', 'nome', 'empresa')
     */
    private function buscarValorFlexivel(array $dados, string ...$chavesPossiveis)
    {
        // 1. Tenta correspondência exata
        foreach ($chavesPossiveis as $chave) {
            if (isset($dados[$chave])) return $dados[$chave];
        }

        // 2. Tenta correspondência normalizada (sem acentos, minúsculo)
        // Cria um mapa normalizado da linha atual apenas se necessário
        $dadosNormalizados = [];
        foreach ($dados as $k => $v) {
            $keyNorm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $k)));
            $dadosNormalizados[$keyNorm] = $v;
        }

        foreach ($chavesPossiveis as $chave) {
            $chaveNorm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $chave)));
            if (isset($dadosNormalizados[$chaveNorm])) return $dadosNormalizados[$chaveNorm];
        }

        return null;
    }

    private function buscarOuCriarClienteParaOs(?string $razaoSocial, ?string $cnpj): Cliente
    {
        $cnpj = $cnpj ? preg_replace('/\D/', '', $cnpj) : null;
        $razao = $razaoSocial ? mb_strtoupper(trim($razaoSocial)) : null;

        $cliente = null;
        $colunasTabelaFlip = $this->obterColunasClientes();
        if ($cnpj) {
            $cliente = Cliente::withTrashed()->where('cnpj', $cnpj)->first();
        }

        if (!$cliente && $razao) {
            $cliente = Cliente::withTrashed()
                ->whereRaw('upper(razao_social) = ?', [$razao])
                ->first();
        }

        if ($cliente) {
            if ($cliente->trashed()) {
                $cliente->restore();
            }
            return $cliente;
        }

        // Se não há CNPJ, gera um placeholder para permitir a criação e posterior ajuste pelo usuário
        if (!$cnpj) {
            $cnpj = $this->gerarCnpjPlaceholder();
        }

        if (empty($razao)) {
            $razao = 'CLIENTE IMPORTADO';
        }

        return Cliente::create([
            'cnpj'         => $cnpj,
            'razao_social' => $razao,
            'status'       => 'ativo',
            'observacoes'  => 'Criado automaticamente via importação (CNPJ placeholder, ajuste posteriormente).',
        ]);
    }

    /**
     * Cache das colunas da tabela de clientes para evitar chamadas repetidas ao schema.
     */
    private function obterColunasClientes(): array
    {
        if (empty($this->cacheColunasClientes)) {
            $colunas = Schema::getColumnListing('clientes');
            $this->cacheColunasClientes = array_flip($colunas);
        }
        return $this->cacheColunasClientes;
    }

    private function parseMoneyString($valor): float
    {
        if (is_null($valor) || $valor === '') return 0.0;
        if (is_numeric($valor)) return (float) $valor;

        $str = preg_replace('/[^0-9,.-]/', '', (string) $valor);
        if ($str === '') return 0.0;

        // Se houver vírgula na parte decimal (padrão BR)
        if (str_contains($str, ',') && strrpos($str, ',') > strrpos($str, '.')) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        }

        return is_numeric($str) ? (float) $str : 0.0;
    }

    private function parseDataBr(?string $data): ?Carbon
    {
        if (empty($data)) return null;
        try {
            return Carbon::createFromFormat('d/m/Y', str_replace(' ', '', $data));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Gera um CNPJ placeholder (14 dígitos) para permitir criação de cliente quando a planilha não traz documento.
     */
    private function gerarCnpjPlaceholder(): string
    {
        do {
            $fake = (string) random_int(10000000000000, 99999999999999); // 14 dígitos
        } while (Cliente::withTrashed()->where('cnpj', $fake)->exists());

        return $fake;
    }

private function detectarAcaoPelosDados(array $dados): string
    {
        if (empty($dados)) return 'generico';
        
        // Pega as chaves da primeira linha para análise
        $primeiraLinha = $dados[0] ?? [];
        if (!is_array($primeiraLinha)) return 'generico';

        $keys = strtolower(implode(',', array_keys($primeiraLinha)));

        // Regras de detecção
        if (str_contains($keys, 'titulos') || (str_contains($keys, 'servico') && str_contains($keys, 'total'))) return 'gerar_os';
        if (str_contains($keys, 'cnpj') && !str_contains($keys, 'servico')) return 'importar_clientes';
        if (str_contains($keys, 'servico') || str_contains($keys, 'valor')) return 'gerar_os';

        return 'importar_clientes'; // Default mais seguro para seu caso de uso
    }

    /**
     * Define sucesso da importação: considera sucesso se houve ao menos um novo/atualizado.
     * Se nenhum registro foi salvo e só houveram erros, sinaliza failure para o front.
     */
    private function avaliarSucessoImportacao(?array $detalhes, string $acao): bool
    {
        if (!is_array($detalhes) || !isset($detalhes['resumo'])) {
            return false;
        }

        $stats = $detalhes['resumo'];
        if (in_array($acao, ['gerar_os', 'ordem_servico'])) {
            return (int)($stats['criados'] ?? 0) > 0;
        }

        $importados = (int)($stats['novos'] ?? 0) + (int)($stats['atualizados'] ?? 0);
        return $importados > 0;
    }

    /**
     * Normaliza o retorno do N8N para garantir que o front receba dados estruturados
     * compatíveis com o botão de importação.
     */
    private function normalizarRespostaN8n($body): array
    {
        $mensagem = is_array($body) ? ($body['mensagem'] ?? null) : null;
        $acaoSugerida = is_array($body) ? ($body['acao_sugerida'] ?? null) : null;

        $dadosEstruturados = is_array($body)
            ? ($body['dados_estruturados'] ?? ($body['dados'] ?? null))
            : null;

        // Se já veio estruturado, apenas completa campos que faltam
        if (is_array($dadosEstruturados) && isset($dadosEstruturados['dados_mapeados'])) {
            // Completa metadados de clientes ou OS (reavalie tipo pelo conteúdo)
            if (($dadosEstruturados['tipo'] ?? null) === 'gerar_os' || $this->pareceOrdemServico($dadosEstruturados['dados_mapeados'])) {
                $dadosEstruturados = $this->completarMetadadosOrdensServico(
                    ($dadosEstruturados['tipo'] ?? null) === 'gerar_os'
                        ? $dadosEstruturados
                        : $this->montarPayloadOrdensServico($dadosEstruturados['dados_mapeados'])
                );
                $acaoSugerida = 'gerar_os';
            } else {
                $dadosEstruturados = $this->completarMetadadosClientes($dadosEstruturados);
            }
            $acaoSugerida ??= $dadosEstruturados['acao_sugerida'] ?? null;
        } else {
            $listaClientes = $this->extrairListaBrutaClientes($dadosEstruturados ?? $body);

            if ($listaClientes) {
                $dadosEstruturados = $this->montarPayloadClientes($listaClientes);
                $acaoSugerida ??= 'importar_clientes';
            } else {
                $listaOs = $this->extrairListaBrutaOrdensServico($dadosEstruturados ?? $body);
                if ($listaOs) {
                    $dadosEstruturados = $this->montarPayloadOrdensServico($listaOs);
                    $acaoSugerida ??= 'gerar_os';
                }
            }
        }

        if (!$mensagem && $dadosEstruturados && isset($dadosEstruturados['dados_mapeados'])) {
            $tipoMsg = ($dadosEstruturados['tipo'] ?? '') === 'gerar_os'
                ? 'ordens de serviço'
                : 'clientes';
            $mensagem = "📥 Encontrei " . count($dadosEstruturados['dados_mapeados']) . " {$tipoMsg} prontos para importar.";
        }

        if (!$mensagem) {
            $mensagem = $this->extrairMensagemTexto($body);
        }

        return [
            'mensagem' => $mensagem,
            'dados_estruturados' => $dadosEstruturados,
            'acao_sugerida' => $acaoSugerida,
        ];
    }

    /**
     * Extrai uma lista de clientes quando o N8N retorna em formatos como
     * [{ output: [...] }] ou simplesmente uma lista de registros.
     */
    private function extrairListaBrutaClientes($dados)
    {
        if (!is_array($dados)) return null;

        // Se parecer OS, devolve null para não cair em importação de clientes
        if ($this->pareceOrdemServico($dados)) {
            return null;
        }

        if (isset($dados['dados_mapeados']) && is_array($dados['dados_mapeados']) && $this->pareceListaClientes($dados['dados_mapeados'])) {
            return $dados['dados_mapeados'];
        }

        if (isset($dados['output']) && is_array($dados['output'])) {
            if ($this->pareceListaClientes($dados['output'])) {
                return $dados['output'];
            }
            if ($this->contemListaClientes($dados['output'])) {
                return $this->pegarListaClientesDeContainer($dados['output']);
            }
            if ($this->pareceOrdemServico($dados['output'])) {
                return [$dados['output']];
            }
        }

        if (isset($dados['dados']) && is_array($dados['dados']) && $this->pareceListaClientes($dados['dados'])) {
            return $dados['dados'];
        }

        if (array_is_list($dados) && $this->pareceListaClientes($dados)) {
            return $dados;
        }

        if (array_is_list($dados)) {
            foreach ($dados as $item) {
                if ($this->pareceOrdemServico($item)) {
                    continue;
                }
                if (isset($item['output']) && is_array($item['output']) && $this->pareceListaClientes($item['output'])) {
                    return $item['output'];
                }
                if (isset($item['output']) && is_array($item['output']) && $this->contemListaClientes($item['output'])) {
                    return $this->pegarListaClientesDeContainer($item['output']);
                }
                if (isset($item['output']) && is_array($item['output']) && $this->pareceOrdemServico($item['output'])) {
                    return [$item['output']];
                }
            }
        }

        return null;
    }

    private function extrairListaBrutaOrdensServico($dados)
    {
        if (!is_array($dados)) return null;

        if ($this->pareceListaClientes($dados)) {
            return null;
        }

        if (isset($dados['dados_mapeados']) && is_array($dados['dados_mapeados']) && $this->pareceOrdemServico($dados['dados_mapeados'])) {
            return array_is_list($dados['dados_mapeados']) ? $dados['dados_mapeados'] : [$dados['dados_mapeados']];
        }

        if (isset($dados['output']) && is_array($dados['output']) && $this->pareceOrdemServico($dados['output'])) {
            return array_is_list($dados['output']) ? $dados['output'] : [$dados['output']];
        }

        if (isset($dados['dados']) && is_array($dados['dados']) && $this->pareceOrdemServico($dados['dados'])) {
            return array_is_list($dados['dados']) ? $dados['dados'] : [$dados['dados']];
        }

        if (array_is_list($dados) && $this->pareceOrdemServico($dados)) {
            return $dados;
        }

        if (array_is_list($dados)) {
            foreach ($dados as $item) {
                if (isset($item['output']) && is_array($item['output']) && $this->pareceOrdemServico($item['output'])) {
                    return array_is_list($item['output']) ? $item['output'] : [$item['output']];
                }
            }
        }

        return null;
    }

    private function montarPayloadClientes(array $lista): array
    {
        return [
            'sucesso'        => true,
            'tipo'           => 'importar_clientes',
            'acao_sugerida'  => 'importar_clientes',
            'dados_mapeados' => $lista,
            'colunas'        => $this->montarColunasClientes($lista),
            'metadata'       => ['fonte' => 'n8n'],
        ];
    }

    private function montarPayloadOrdensServico(array $lista): array
    {
        $dados = [];
        foreach ($lista as $os) {
            if (!is_array($os)) continue;

            $empresa      = $this->buscarValorFlexivel($os, 'empresa', 'cliente', 'razao_social', 'nome');
            $titulos      = $os['titulos'] ?? [];
            $totalGeral   = $os['total_geral'] ?? null;
            $primeiraData = $titulos[0]['data_cobranca'] ?? ($os['data_cobranca'] ?? null);
            $qtdTitulos   = is_array($titulos) ? count($titulos) : 0;
            $qtdVidas     = $os['numero_de_funcionarios'] ?? null;

            $dados[] = [
                'empresa'         => $empresa,
                'data_cobranca'   => $primeiraData,
                'total_geral'     => $totalGeral,
                'qtd_titulos'     => $qtdTitulos,
                'qtd_vidas'       => $qtdVidas,
                'titulos_resumo'  => $this->montarResumoTitulos($titulos),
                'titulos'         => $titulos,
                'raw'             => $os,
            ];
        }

        return [
            'sucesso'        => true,
            'tipo'           => 'gerar_os',
            'acao_sugerida'  => 'gerar_os',
            'dados_mapeados' => $dados,
            'colunas'        => $this->montarColunasOrdensServico($dados),
            'metadata'       => ['fonte' => 'n8n'],
        ];
    }

    private function completarMetadadosClientes(array $dadosEstruturados): array
    {
        $lista = $dadosEstruturados['dados_mapeados'] ?? [];

        $dadosEstruturados['sucesso']       = $dadosEstruturados['sucesso'] ?? true;
        $dadosEstruturados['tipo']          = $dadosEstruturados['tipo'] ?? 'importar_clientes';
        $dadosEstruturados['acao_sugerida'] = $dadosEstruturados['acao_sugerida'] ?? 'importar_clientes';
        $dadosEstruturados['colunas']       = $dadosEstruturados['colunas'] ?? $this->montarColunasClientes($lista);
        $dadosEstruturados['metadata']      = $dadosEstruturados['metadata'] ?? ['fonte' => 'n8n'];

        return $dadosEstruturados;
    }

    private function completarMetadadosOrdensServico(array $dadosEstruturados): array
    {
        $lista = $dadosEstruturados['dados_mapeados'] ?? [];

        $dadosEstruturados['sucesso']       = $dadosEstruturados['sucesso'] ?? true;
        $dadosEstruturados['tipo']          = $dadosEstruturados['tipo'] ?? 'gerar_os';
        $dadosEstruturados['acao_sugerida'] = $dadosEstruturados['acao_sugerida'] ?? 'gerar_os';
        $dadosEstruturados['colunas']       = $dadosEstruturados['colunas'] ?? $this->montarColunasOrdensServico($lista);
        $dadosEstruturados['metadata']      = $dadosEstruturados['metadata'] ?? ['fonte' => 'n8n'];

        return $dadosEstruturados;
    }

    private function montarColunasOrdensServico(array $lista): array
    {
        // Define colunas amigáveis para não exibir arrays complexos
        return [
            ['key' => 'empresa',        'label' => 'Empresa'],
            ['key' => 'data_cobranca',  'label' => 'Data Cobrança'],
            ['key' => 'qtd_titulos',    'label' => 'Qtd Títulos'],
            ['key' => 'qtd_vidas',      'label' => 'Qtd Vidas'],
            ['key' => 'total_geral',    'label' => 'Total Geral'],
            ['key' => 'titulos_resumo', 'label' => 'Resumo Títulos'],
        ];
    }

    private function montarColunasClientes(array $lista): array
    {
        $primeiraLinha = $lista[0] ?? [];
        if (!is_array($primeiraLinha)) return [];

        $colunas = [];
        foreach (array_keys($primeiraLinha) as $key) {
            $colunas[] = [
                'key'   => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
            ];
        }

        return $colunas;
    }

    /**
     * Verifica rapidamente se um array parece conter clientes (campos de CNPJ, razão social, etc).
     */
    private function pareceListaClientes($dados): bool
    {
        if (!is_array($dados) || !array_is_list($dados) || empty($dados) || !is_array($dados[0])) {
            return false;
        }

        $primeiraLinha = array_change_key_case($dados[0], CASE_LOWER);
        $marcadores = [
            'razao_social', 'nome', 'empresa', 'cliente',
            'cnpj', 'cpf', 'nome_fantasia', 'email',
            'telefone', 'celular', 'cep', 'logradouro',
            'numero', 'bairro', 'cidade', 'uf'
        ];

        foreach ($marcadores as $campo) {
            if (array_key_exists($campo, $primeiraLinha)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se um array parece representar uma ordem de serviço (dados de faturamento SOC).
     */
    private function pareceOrdemServico($dados): bool
    {
        if (!is_array($dados)) return false;

        $arr = $dados;
        if (array_is_list($dados)) {
            $arr = $dados[0] ?? [];
        }

        if (!is_array($arr)) return false;

        $keys = array_change_key_case(array_keys($arr));

        return in_array('titulos', $keys, true) || in_array('empresa', $keys, true);
    }

    private function montarResumoTitulos($titulos): string
    {
        if (!is_array($titulos) || empty($titulos)) return '';

        $nomes = [];
        foreach (array_slice($titulos, 0, 3) as $t) {
            $nomes[] = $t['produto_servico'] ?? ($t['descricao'] ?? 'Serviço');
        }

        $sufixo = count($titulos) > 3 ? ' + ' . (count($titulos) - 3) . ' outros' : '';

        return implode(', ', $nomes) . $sufixo;
    }

    /**
     * Extrai texto principal de respostas simples do N8N (ex: [{output: "Olá..."}]).
     */
    private function extrairMensagemTexto($body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_array($body)) {
            if (isset($body['mensagem']) && is_string($body['mensagem'])) {
                return $body['mensagem'];
            }
            if (isset($body['output']) && is_string($body['output'])) {
                return $body['output'];
            }

            // Caso venha como lista com um item { output: "..." }
            if (array_is_list($body) && count($body) === 1 && isset($body[0]['output']) && is_string($body[0]['output'])) {
                return $body[0]['output'];
            }
        }

        return is_array($body) ? json_encode($body) : (string) $body;
    }

    /**
     * Responde consultas simples com dados locais (clientes, faturas, ordens de serviço)
     * sem precisar ir ao N8N.
     */
    private function responderLocalmente(string $mensagem): ?string
    {
        $texto = mb_strtolower($mensagem);

        // Quantidade de clientes
        if (str_contains($texto, 'quantos') && str_contains($texto, 'cliente')) {
            $ativos = Cliente::where('status', 'ativo')->count();
            $inativos = Cliente::where('status', 'inativo')->count();
            $total = $ativos + $inativos;
            return "Temos {$total} clientes cadastrados ({$ativos} ativos, {$inativos} inativos).";
        }

        // Quantidade de faturas
        if (str_contains($texto, 'quantas') && str_contains($texto, 'fatura')) {
            $pendentes = Fatura::where('status', 'pendente')->count();
            $abertas = Fatura::where('status', 'aberta')->count();
            $total = Fatura::count();
            return "Faturas: {$total} no total, {$pendentes} pendentes, {$abertas} abertas.";
        }

        // Quantidade de ordens de serviço
        if ((str_contains($texto, 'quantas') || str_contains($texto, 'quantos'))
            && (str_contains($texto, 'ordem') || str_contains($texto, 'os'))
        ) {
            $pendentes = OrdemServico::where('status', 'pendente')->count();
            $aprovadas = OrdemServico::where('status', 'aprovada')->count();
            $total = OrdemServico::count();
            return "Ordens de serviço: {$total} no total, {$pendentes} pendentes, {$aprovadas} aprovadas.";
        }

        // Listar clientes que têm OS geradas
        if (str_contains($texto, 'cliente') && (str_contains($texto, 'ordem') || str_contains($texto, 'os'))) {
            $clientes = OrdemServico::with('cliente')
                ->select('cliente_id')
                ->whereNotNull('cliente_id')
                ->groupBy('cliente_id')
                ->limit(5)
                ->get()
                ->map(fn($os) => optional($os->cliente)->razao_social)
                ->filter()
                ->values();

            if ($clientes->isNotEmpty()) {
                $lista = $clientes->implode(', ');
                $mais = OrdemServico::distinct('cliente_id')->count() > $clientes->count() ? ' ...' : '';
                return "Clientes com OS geradas: {$lista}{$mais}.";
            }
        }

        return null;
    }

    /**
     * Verifica se um array associativo possui alguma chave de container de clientes (empresas, clientes, registros).
     */
    private function contemListaClientes(array $dados): bool
    {
        $possiveis = ['empresas', 'clientes', 'registros', 'data', 'dados'];
        foreach ($possiveis as $chave) {
            if (isset($dados[$chave]) && is_array($dados[$chave]) && $this->pareceListaClientes($dados[$chave])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extrai a lista de clientes de um container com chaves como empresas/clientes.
     */
    private function pegarListaClientesDeContainer(array $dados): array
    {
        $possiveis = ['empresas', 'clientes', 'registros', 'data', 'dados'];
        foreach ($possiveis as $chave) {
            if (isset($dados[$chave]) && is_array($dados[$chave]) && $this->pareceListaClientes($dados[$chave])) {
                return $dados[$chave];
            }
        }
        return [];
    }
}
