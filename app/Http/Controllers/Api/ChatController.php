<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cadastros\CriarClienteAction;
use App\Actions\Financeiro\CriarDespesaAction;
use App\Actions\Financeiro\CriarTituloAction;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\OrdemServicoRateio;
use App\Models\Fatura;
use App\Services\Ai\AiChatGatewayService;
use App\Services\Ai\ChatAttachmentProcessingService;
use App\Services\GoogleDriveFileMirrorService;
use App\Services\Rag\ChatKnowledgeIngestionService;
use Illuminate\Http\Request;
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
     * 1. FLUXO DE CHAT (ENVIO DE MENSAGEM PARA O RUNTIME DE IA)
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
            $arquivoIngestao = null;
            $ragIngestao = null;
            $anexoProcessado = null;
            $tipoAnexo = null;

            // 1.1 Processamento e Validação de Arquivo
            if ($request->hasFile('arquivo') && $request->file('arquivo')->isValid()) {
                $arquivo = $request->file('arquivo');
                
                if ($arquivo->getSize() > self::MAX_FILE_SIZE) {
                    return response()->json(['success' => false, 'message' => 'Arquivo muito grande (Máx 10MB).'], 422);
                }

                $nomeOriginal = $request->input('arquivo_nome', $arquivo->getClientOriginalName());
                $mimeTypeInformado = $request->input('arquivo_mime_type');
                $mimeType = $this->resolverMimeTypeArquivo(
                    $arquivo->getClientMimeType(),
                    $mimeTypeInformado,
                    $arquivo->getClientOriginalExtension()
                );
                $extensao = $this->resolverExtensaoArquivo(
                    $arquivo->getClientOriginalExtension(),
                    $mimeType
                );
                $nomeArquivo = $this->normalizarNomeArquivoChat(
                    $nomeOriginal,
                    $extensao,
                    $mimeType
                );

                $arquivoData = [
                    'nome'      => $nomeArquivo,
                    'extensao'  => $extensao,
                    'mime_type' => $mimeType,
                    'tamanho'   => $arquivo->getSize(),
                    'base64'    => base64_encode(file_get_contents($arquivo->getRealPath())),
                ];

                /** @var ChatAttachmentProcessingService $attachmentProcessor */
                $attachmentProcessor = app(ChatAttachmentProcessingService::class);
                $tipoAnexo = $attachmentProcessor->inferirTipo(
                    $arquivoData['mime_type'] ?? null,
                    $arquivoData['extensao'] ?? null
                );
                $anexoProcessado = $attachmentProcessor->processar($arquivo, $mensagem);

                $arquivoIngestao = $this->espelharArquivoNoDriveSeSolicitado(
                    request: $request,
                    userId: $user->id,
                    sessionId: $sessionId,
                    tipoProcessamento: $tipoProcessamento,
                    processedAttachment: $anexoProcessado,
                );

                if (($arquivoIngestao['required'] ?? false) && !($arquivoIngestao['success'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'message' => $arquivoIngestao['message'] ?? 'Falha ao enviar arquivo para o Google Drive.',
                        'arquivo_ingestao' => $arquivoIngestao,
                    ], 502);
                }

                $ragIngestao = $this->indexarArquivoNoRagSeConfigurado(
                    request: $request,
                    userId: $user->id,
                    sessionId: $sessionId,
                    tipoProcessamento: $tipoProcessamento,
                    processedAttachment: $anexoProcessado,
                    driveIngestion: $arquivoIngestao,
                );
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
                'metadata'   => $arquivoData ? array_filter([
                    'file_name' => $arquivoData['nome'],
                    'file_mime_type' => $arquivoData['mime_type'] ?? null,
                    'file_kind' => $tipoAnexo,
                    'processed_attachment' => app(ChatAttachmentProcessingService::class)->resumoMetadata($anexoProcessado),
                    'drive_ingestion' => $arquivoIngestao,
                    'rag_ingestion' => $ragIngestao,
                ]) : null
            ]);

            // Respostas rápidas locais (consultas simples a clientes/faturas/OS)
            $respostaRapida = $arquivoData ? null : $this->responderLocalmente($mensagem);
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
                    'arquivo_ingestao'   => $arquivoIngestao,
                    'rag_ingestao'       => $ragIngestao,
                ]);
            }

            // 1.3 Envio para N8N (Core Logic)
            $respostaIa = $this->conectarComN8n(
                $mensagem,
                $user,
                $sessionId,
                $arquivoData,
                $tipoProcessamento,
                $arquivoIngestao,
                $anexoProcessado
            );

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
                'arquivo_ingestao'   => $arquivoIngestao,
                'rag_ingestao'       => $ragIngestao,
            ]);

        } catch (\Throwable $e) {
            Log::error('Erro ChatController@enviarMensagem: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()], 500);
        }
    }

    public function historico(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }

        $limit = max(1, min((int) $request->input('limit', 50), 200));
        $sessionId = $request->input('session_id');

        $query = ChatMessage::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (!empty($sessionId)) {
            $query->where('session_id', $sessionId);
        }

        $mensagens = $query->limit($limit)->get()->reverse()->values();

        return response()->json([
            'success' => true,
            'data' => $mensagens,
        ]);
    }

    public function limparHistorico(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }

        $sessionId = $request->input('session_id');

        $query = ChatMessage::where('user_id', $user->id);
        if (!empty($sessionId)) {
            $query->where('session_id', $sessionId);
        }

        $apagadas = $query->delete();

        return response()->json([
            'success' => true,
            'message' => 'Histórico removido com sucesso.',
            'apagadas' => $apagadas,
        ]);
    }

    /**
     * Comunicação HTTP com o runtime configurado (LangChain ou N8N)
     */
private function conectarComN8n($mensagem, $user, $sessionId, $arquivoData, $tipoProcessamento, $arquivoIngestao = null, $anexoProcessado = null): array
    {
        $isArquivo = !empty($arquivoData);
        $payload = [
            'mensagem'           => $mensagem,
            'user_id'            => $user->id,
            'user_name'          => $user->name,
            'user_email'         => $user->email ?? null,
            'session_id'         => $sessionId,
            'historico'          => $this->carregarHistoricoContexto($user->id, $sessionId),
            'tipo_processamento' => $tipoProcessamento,
            'timestamp'          => now()->toISOString(),
            'contexto'           => [
                'origem' => 'laravel_chat_gateway',
                'timezone' => config('app.timezone'),
                'acoes_confirmaveis' => [
                    'criar_cliente',
                    'sincronizar_clientes',
                    'inativar_cliente',
                    'reativar_cliente',
                    'criar_despesa',
                    'criar_conta_pagar',
                    'criar_conta_receber',
                    'criar_fatura',
                    'gerar_fatura',
                    'gerar_boleto',
                    'excluir_boleto',
                    'excluir_fatura',
                    'baixar_titulo',
                    'baixar_despesa',
                    'renegociar_titulo',
                    'emitir_nfse',
                    'importar_clientes',
                    'gerar_os',
                ],
                'fontes' => [
                    'mysql_operacional' => true,
                    'vector_store_documental' => true,
                ],
                'arquivo_ingestao' => $arquivoIngestao,
            ],
        ];

        if ($isArquivo) {
            $payload['arquivo'] = $this->arquivoParaRuntime($arquivoData);
        }

        if (!empty($anexoProcessado)) {
            $payload['anexo_processado'] = $anexoProcessado;
        }

        /** @var AiChatGatewayService $gateway */
        $gateway = app(AiChatGatewayService::class);
        $resultado = $gateway->processarChat($payload, $isArquivo);

        if (!$resultado['success']) {
            return [
                'mensagem' => $resultado['message'] ?? '❌ Falha de conexão com IA.',
                'dados_estruturados' => null,
            ];
        }

        $body = $resultado['body'];
        $normalizado = $this->normalizarRespostaIa($body);

        return [
            'mensagem'           => $normalizado['mensagem'] ?? (is_string($body) ? $body : json_encode($body)),
            'dados_estruturados' => $normalizado['dados_estruturados'] ?? null,
            'acao_sugerida'      => $normalizado['acao_sugerida'] ?? ($normalizado['dados_estruturados']['acao_sugerida'] ?? null),
        ];
    }

    private function arquivoParaRuntime(array $arquivoData): array
    {
        return $arquivoData;
    }

    private function resolverExtensaoArquivo(?string $extensaoOriginal, ?string $mimeType): string
    {
        $extensao = strtolower(trim((string) $extensaoOriginal));
        if ($extensao !== '') {
            return $extensao;
        }

        $mime = strtolower(trim((string) $mimeType));

        return match ($mime) {
            'audio/webm' => 'webm',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/aac' => 'aac',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => '',
        };
    }

    private function resolverMimeTypeArquivo(?string $mimeTypeOriginal, ?string $mimeTypeInformado, ?string $extensaoOriginal): string
    {
        $mimeInformado = strtolower(trim((string) $mimeTypeInformado));
        if ($mimeInformado !== '') {
            return $mimeInformado;
        }

        $mimeOriginal = strtolower(trim((string) $mimeTypeOriginal));
        if ($mimeOriginal !== '' && $mimeOriginal !== 'application/octet-stream') {
            return $mimeOriginal;
        }

        $extensao = strtolower(trim((string) $extensaoOriginal));

        return match ($extensao) {
            'webm' => 'audio/webm',
            'ogg' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/m4a',
            'aac' => 'audio/aac',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => $mimeOriginal,
        };
    }

    private function normalizarNomeArquivoChat(?string $nomeOriginal, string $extensao, ?string $mimeType): string
    {
        $nome = trim((string) $nomeOriginal);
        $mime = strtolower(trim((string) $mimeType));

        $nomeEhGenerico = $nome === ''
            || strtolower($nome) === 'blob'
            || strtolower($nome) === 'audio'
            || strtolower($nome) === 'image';

        if (!$nomeEhGenerico && str_contains($nome, '.')) {
            return $nome;
        }

        $prefixo = str_starts_with($mime, 'audio/') ? 'gravacao_chat' : 'anexo_chat';
        $sufixo = now()->format('Ymd_His');

        return $extensao !== ''
            ? "{$prefixo}_{$sufixo}.{$extensao}"
            : "{$prefixo}_{$sufixo}";
    }

    private function carregarHistoricoContexto(int $userId, string $sessionId, int $limit = 12): array
    {
        return ChatMessage::query()
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(function (ChatMessage $message) {
                return [
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    private function espelharArquivoNoDriveSeSolicitado(
        Request $request,
        int $userId,
        string $sessionId,
        string $tipoProcessamento,
        ?array $processedAttachment = null,
    ): ?array
    {
        $espelhar = $request->has('espelhar_no_drive')
            ? $request->boolean('espelhar_no_drive')
            : (bool) config('chatbot.chat_upload.mirror_to_drive', true);

        if (!$espelhar || !$request->hasFile('arquivo') || !$request->file('arquivo')->isValid()) {
            return null;
        }

        $required = $request->has('drive_required')
            ? $request->boolean('drive_required')
            : (bool) config('chatbot.chat_upload.mirror_to_drive_required');

        /** @var GoogleDriveFileMirrorService $mirror */
        $mirror = app(GoogleDriveFileMirrorService::class);

        if (!$mirror->isConfigured()) {
            return [
                'success' => false,
                'attempted' => true,
                'required' => $required,
                'provider' => 'google_drive',
                'message' => 'Integração com Google Drive não configurada.',
            ];
        }

        try {
            return $mirror->mirrorChatUpload($request->file('arquivo'), [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'tipo_processamento' => $tipoProcessamento,
                'processed_attachment' => $processedAttachment,
            ]) + [
                'attempted' => true,
                'required' => $required,
            ];
        } catch (\Throwable $e) {
            Log::warning('Falha ao espelhar arquivo do chat no Google Drive', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'tipo_processamento' => $tipoProcessamento,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'attempted' => true,
                'required' => $required,
                'provider' => 'google_drive',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function indexarArquivoNoRagSeConfigurado(
        Request $request,
        int $userId,
        string $sessionId,
        string $tipoProcessamento,
        ?array $processedAttachment = null,
        ?array $driveIngestion = null,
    ): ?array {
        if (!(bool) config('chatbot.chat_upload.index_to_rag', true)) {
            return null;
        }

        if (!$request->hasFile('arquivo') || !$request->file('arquivo')->isValid()) {
            return null;
        }

        try {
            /** @var ChatKnowledgeIngestionService $ingestion */
            $ingestion = app(ChatKnowledgeIngestionService::class);

            return $ingestion->ingestChatUpload(
                file: $request->file('arquivo'),
                context: [
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'tipo_processamento' => $tipoProcessamento,
                ],
                processedAttachment: $processedAttachment,
                driveIngestion: $driveIngestion,
            );
        } catch (\Throwable $e) {
            Log::warning('Falha ao indexar anexo do chat no RAG', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'tipo_processamento' => $tipoProcessamento,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => 'rag_mysql',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
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

    if ($this->deveDelegarConfirmacaoAoRuntime($acao, $metadata)) {
        return $this->confirmarAcaoNoRuntime($request, $acao, $metadata);
    }

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

            'criar_cliente', 'cliente'
                => $this->processarCriacaoClientes($dadosParaProcessar),

            // Ordens de serviço
            'gerar_os', 'ordem_servico'
                => $this->processarImportacaoOrdensServico($dadosParaProcessar),

            'criar_despesa', 'despesa', 'criar_conta_pagar', 'conta_pagar'
                => $this->processarCriacaoDespesas($dadosParaProcessar, $request->user()?->id),

            'criar_conta_receber', 'titulo_receber', 'criar_titulo_receber'
                => $this->processarCriacaoTitulos($dadosParaProcessar, 'receber'),

            default
                => throw new \InvalidArgumentException("Ação não suportada: {$acao}"),
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

    private function deveDelegarConfirmacaoAoRuntime(?string $acao, array $metadata): bool
    {
        if (strtolower((string) config('chatbot.runtime.driver')) !== 'langchain') {
            return false;
        }

        if (!is_string($acao) || !in_array($acao, [
            'criar_cliente',
            'sincronizar_clientes',
            'importar_clientes',
            'inativar_cliente',
            'reativar_cliente',
            'baixar_titulo',
            'baixar_despesa',
            'gerar_boleto',
            'excluir_boleto',
            'excluir_fatura',
            'renegociar_titulo',
            'emitir_nfse',
            'cliente',
            'criar_despesa',
            'despesa',
            'criar_conta_pagar',
            'conta_pagar',
            'criar_conta_receber',
            'titulo_receber',
            'criar_titulo_receber',
            'criar_fatura',
            'gerar_fatura',
            'gerar_boleto',
            'excluir_boleto',
            'excluir_fatura',
            'fatura',
        ], true)) {
            return false;
        }

        return !empty($metadata['runtime_pending_action_id'])
            || (($metadata['fonte'] ?? null) === 'langchain-runtime');
    }

    private function confirmarAcaoNoRuntime(Request $request, ?string $acao, array $metadata)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        /** @var AiChatGatewayService $gateway */
        $gateway = app(AiChatGatewayService::class);
        $resultado = $gateway->confirmarAcao([
            'acao' => $acao,
            'dados' => $request->input('dados', []),
            'metadata' => $metadata,
            'decision' => 'approve',
            'session_id' => $request->input('session_id', 'session_' . $user->id),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'timestamp' => now()->toISOString(),
        ]);

        if (!$resultado['success']) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'] ?? 'Falha ao confirmar ação no runtime.',
                'detalhes' => $resultado['body'] ?? null,
            ], 502);
        }

        $body = is_array($resultado['body']) ? $resultado['body'] : [];
        $requiresMoreInfo = !empty(data_get($body, 'detalhes.pendencias'))
            || !empty($body['requires_more_info'])
            || !empty($body['runtime_requires_more_info']);

        if ($requiresMoreInfo) {
            return response()->json([
                'success' => true,
                'completed' => false,
                'requires_more_info' => true,
                'message' => $body['message'] ?? 'Ainda faltam alguns dados para concluir a ação.',
                'detalhes' => $body['detalhes'] ?? null,
            ]);
        }

        $status = !empty($body['success']) ? 200 : 422;

        return response()->json([
            'success' => (bool) ($body['success'] ?? false),
            'completed' => (bool) ($body['success'] ?? false),
            'requires_more_info' => false,
            'message' => $body['message'] ?? 'Ação processada pelo runtime.',
            'detalhes' => $body['detalhes'] ?? null,
        ], $status);
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

    private function processarCriacaoClientes(array $registros): array
    {
        /** @var CriarClienteAction $action */
        $action = app(CriarClienteAction::class);

        $stats = ['criados' => 0, 'erros' => 0];
        $criados = [];
        $erros = [];

        foreach ($registros as $index => $row) {
            if (!is_array($row)) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': registro inválido.';
                continue;
            }

            try {
                $payload = $this->normalizarPayloadCliente($row);
                if (empty($payload['razao_social'])) {
                    throw new \Exception('Razão social obrigatória.');
                }
                if (empty($payload['cnpj'])) {
                    throw new \Exception('CNPJ obrigatório para criar cliente.');
                }

                $existente = Cliente::withTrashed()
                    ->where('cnpj', $payload['cnpj'])
                    ->first();

                if ($existente) {
                    if ($existente->trashed()) {
                        $existente->restore();
                        $existente->update($payload);
                        $criados[] = $existente->fresh()->toArray();
                        $stats['criados']++;
                        continue;
                    }

                    throw new \Exception('Cliente já existe para o CNPJ informado.');
                }

                $cliente = $action->execute($payload);

                $criados[] = $cliente->toArray();
                $stats['criados']++;
            } catch (\Exception $e) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': ' . $e->getMessage();
            }
        }

        return $this->montarRespostaCriacao('cliente(s)', $stats, $criados, $erros);
    }

    private function processarCriacaoDespesas(array $registros, ?int $userId = null): array
    {
        /** @var CriarDespesaAction $action */
        $action = app(CriarDespesaAction::class);

        $stats = ['criados' => 0, 'erros' => 0];
        $criados = [];
        $erros = [];

        foreach ($registros as $index => $row) {
            if (!is_array($row)) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': registro inválido.';
                continue;
            }

            try {
                $payload = $this->normalizarPayloadDespesa($row);
                if (empty($payload['descricao'])) {
                    throw new \Exception('Descrição obrigatória para criar despesa.');
                }
                if (empty($payload['data_vencimento'])) {
                    throw new \Exception('Data de vencimento obrigatória.');
                }
                if (($payload['valor_original'] ?? 0) <= 0) {
                    throw new \Exception('Valor da despesa deve ser maior que zero.');
                }

                $despesa = $action->execute($payload, $userId);

                $criados[] = $despesa->toArray();
                $stats['criados']++;
            } catch (\Exception $e) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': ' . $e->getMessage();
            }
        }

        return $this->montarRespostaCriacao('despesa(s)', $stats, $criados, $erros);
    }

    private function processarCriacaoTitulos(array $registros, string $tipo): array
    {
        /** @var CriarTituloAction $action */
        $action = app(CriarTituloAction::class);

        $stats = ['criados' => 0, 'erros' => 0];
        $criados = [];
        $erros = [];

        foreach ($registros as $index => $row) {
            if (!is_array($row)) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': registro inválido.';
                continue;
            }

            try {
                $payload = $this->normalizarPayloadTitulo($row, $tipo);
                if (empty($payload['descricao'])) {
                    throw new \Exception('Descrição obrigatória para criar título.');
                }
                if (empty($payload['data_vencimento'])) {
                    throw new \Exception('Data de vencimento obrigatória.');
                }
                if (($payload['valor_original'] ?? 0) <= 0) {
                    throw new \Exception('Valor do título deve ser maior que zero.');
                }
                if ($tipo === 'receber' && empty($payload['cliente_id'])) {
                    throw new \Exception('Cliente obrigatório para conta a receber.');
                }

                $titulo = $action->execute($payload);

                $criados[] = $titulo->toArray();
                $stats['criados']++;
            } catch (\Exception $e) {
                $stats['erros']++;
                $erros[] = 'Linha ' . ($index + 1) . ': ' . $e->getMessage();
            }
        }

        $label = $tipo === 'receber' ? 'conta(s) a receber' : 'título(s)';

        return $this->montarRespostaCriacao($label, $stats, $criados, $erros);
    }

    private function montarRespostaCriacao(string $label, array $stats, array $criados, array $erros): array
    {
        $mensagem = "✅ {$stats['criados']} {$label} criada(s).";
        if (($stats['erros'] ?? 0) > 0) {
            $mensagem .= " ({$stats['erros']} erro(s)).";
        }

        return [
            'message' => $mensagem,
            'detalhes' => [
                'resumo' => $stats,
                'registros' => array_slice($criados, 0, 10),
                'erros_lista' => array_slice($erros, 0, 5),
            ],
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

        if (is_array($dados) && !array_is_list($dados) && !empty($dados)) {
            return [$dados];
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

    private function normalizarPayloadCliente(array $row): array
    {
        return array_filter([
            'cnpj' => preg_replace('/\D/', '', (string) ($this->buscarValorFlexivel($row, 'cnpj', 'cliente_cnpj', 'documento') ?? '')),
            'razao_social' => $this->buscarValorFlexivel($row, 'razao_social', 'cliente', 'empresa', 'nome'),
            'nome_fantasia' => $this->buscarValorFlexivel($row, 'nome_fantasia', 'fantasia'),
            'email' => $this->buscarValorFlexivel($row, 'email', 'e-mail'),
            'telefone' => $this->buscarValorFlexivel($row, 'telefone', 'tel', 'fixo'),
            'celular' => $this->buscarValorFlexivel($row, 'celular', 'cel', 'whatsapp'),
            'cep' => preg_replace('/\D/', '', (string) ($this->buscarValorFlexivel($row, 'cep') ?? '')),
            'logradouro' => $this->buscarValorFlexivel($row, 'logradouro', 'endereco', 'rua'),
            'numero' => $this->buscarValorFlexivel($row, 'numero', 'num'),
            'bairro' => $this->buscarValorFlexivel($row, 'bairro'),
            'cidade' => $this->buscarValorFlexivel($row, 'cidade', 'municipio'),
            'uf' => strtoupper((string) ($this->buscarValorFlexivel($row, 'uf', 'estado') ?? '')),
            'status' => $this->buscarValorFlexivel($row, 'status') ?? 'ativo',
            'observacoes' => $this->buscarValorFlexivel($row, 'observacoes', 'obs'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function normalizarPayloadDespesa(array $row): array
    {
        $valorOriginal = $this->parseMoneyString(
            $this->buscarValorFlexivel($row, 'valor_original', 'valor', 'valor_total')
        );

        return array_filter([
            'fornecedor_id' => $this->resolverFornecedorId($row),
            'categoria_id' => $this->buscarValorFlexivel($row, 'categoria_id'),
            'descricao' => $this->buscarValorFlexivel($row, 'descricao', 'historico', 'nome'),
            'valor' => $valorOriginal,
            'valor_original' => $valorOriginal,
            'data_emissao' => $this->normalizarDataEntrada($this->buscarValorFlexivel($row, 'data_emissao', 'emissao')) ?? now()->toDateString(),
            'data_vencimento' => $this->normalizarDataEntrada($this->buscarValorFlexivel($row, 'data_vencimento', 'vencimento')),
            'documento_url' => $this->buscarValorFlexivel($row, 'documento_url', 'url_documento'),
            'observacoes' => $this->buscarValorFlexivel($row, 'observacoes', 'obs'),
            'codigo_barras' => $this->buscarValorFlexivel($row, 'codigo_barras', 'linha_digitavel'),
            'status' => $this->buscarValorFlexivel($row, 'status') ?? 'pendente',
            'plano_conta_id' => $this->buscarValorFlexivel($row, 'plano_conta_id'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function normalizarPayloadTitulo(array $row, string $tipo): array
    {
        $valorOriginal = $this->parseMoneyString(
            $this->buscarValorFlexivel($row, 'valor_original', 'valor', 'valor_total')
        );

        return array_filter([
            'cliente_id' => $tipo === 'receber' ? $this->resolverClienteId($row) : null,
            'fornecedor_id' => $tipo === 'pagar' ? $this->resolverFornecedorId($row) : null,
            'fatura_id' => $this->buscarValorFlexivel($row, 'fatura_id'),
            'descricao' => $this->buscarValorFlexivel($row, 'descricao', 'historico', 'nome'),
            'tipo' => $tipo,
            'plano_conta_id' => $this->buscarValorFlexivel($row, 'plano_conta_id'),
            'centro_custo_id' => $this->buscarValorFlexivel($row, 'centro_custo_id'),
            'competencia' => $this->normalizarCompetencia($this->buscarValorFlexivel($row, 'competencia', 'periodo')),
            'numero_titulo' => $this->buscarValorFlexivel($row, 'numero_titulo', 'numero'),
            'nosso_numero' => $this->buscarValorFlexivel($row, 'nosso_numero'),
            'data_emissao' => $this->normalizarDataEntrada($this->buscarValorFlexivel($row, 'data_emissao', 'emissao')) ?? now()->toDateString(),
            'data_vencimento' => $this->normalizarDataEntrada($this->buscarValorFlexivel($row, 'data_vencimento', 'vencimento')),
            'valor_original' => $valorOriginal,
            'valor_juros' => $this->parseMoneyString($this->buscarValorFlexivel($row, 'valor_juros', 'juros')),
            'valor_multa' => $this->parseMoneyString($this->buscarValorFlexivel($row, 'valor_multa', 'multa')),
            'valor_desconto' => $this->parseMoneyString($this->buscarValorFlexivel($row, 'valor_desconto', 'desconto')),
            'status' => $this->buscarValorFlexivel($row, 'status') ?? 'aberto',
            'forma_pagamento' => $this->buscarValorFlexivel($row, 'forma_pagamento', 'forma'),
            'codigo_barras' => $this->buscarValorFlexivel($row, 'codigo_barras'),
            'linha_digitavel' => $this->buscarValorFlexivel($row, 'linha_digitavel'),
            'url_boleto' => $this->buscarValorFlexivel($row, 'url_boleto'),
            'observacoes' => $this->buscarValorFlexivel($row, 'observacoes', 'obs'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function resolverClienteId(array $row): ?int
    {
        $clienteId = $this->buscarValorFlexivel($row, 'cliente_id');
        if ($clienteId) {
            return (int) $clienteId;
        }

        $cnpj = preg_replace('/\D/', '', (string) ($this->buscarValorFlexivel($row, 'cliente_cnpj', 'cnpj_cliente') ?? ''));
        if ($cnpj !== '') {
            $cliente = Cliente::where('cnpj', $cnpj)->first();
            if ($cliente) {
                return $cliente->id;
            }
        }

        $nome = $this->buscarValorFlexivel($row, 'cliente', 'cliente_nome', 'razao_social', 'empresa', 'nome_fantasia');
        if (!$nome) {
            return null;
        }

        $nome = mb_strtoupper(trim((string) $nome));

        $cliente = Cliente::query()
            ->whereRaw('upper(razao_social) = ?', [$nome])
            ->orWhereRaw('upper(nome_fantasia) = ?', [$nome])
            ->first();

        return $cliente?->id;
    }

    private function resolverFornecedorId(array $row): ?int
    {
        $fornecedorId = $this->buscarValorFlexivel($row, 'fornecedor_id');
        if ($fornecedorId) {
            return (int) $fornecedorId;
        }

        $cnpj = preg_replace('/\D/', '', (string) ($this->buscarValorFlexivel($row, 'fornecedor_cnpj', 'cnpj_fornecedor', 'cnpj') ?? ''));
        if ($cnpj !== '') {
            $fornecedor = Fornecedor::query()
                ->where('cnpj', $cnpj)
                ->orWhere('cpf', $cnpj)
                ->first();
            if ($fornecedor) {
                return $fornecedor->id;
            }
        }

        $nome = $this->buscarValorFlexivel($row, 'fornecedor', 'fornecedor_nome', 'nome_fornecedor', 'razao_social_fornecedor');
        if (!$nome) {
            return null;
        }

        $nome = mb_strtoupper(trim((string) $nome));

        $fornecedor = Fornecedor::query()
            ->whereRaw('upper(razao_social) = ?', [$nome])
            ->orWhereRaw('upper(nome_fantasia) = ?', [$nome])
            ->first();

        return $fornecedor?->id;
    }

    private function normalizarDataEntrada($data): ?string
    {
        if (empty($data)) {
            return null;
        }

        if ($data instanceof Carbon) {
            return $data->toDateString();
        }

        $valor = trim((string) $data);
        if ($valor === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $valor;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $valor)) {
            return $this->parseDataBr($valor)?->toDateString();
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizarCompetencia($competencia): ?string
    {
        if (empty($competencia)) {
            return null;
        }

        $valor = trim((string) $competencia);

        if (preg_match('/^\d{2}\/\d{4}$/', $valor)) {
            return Carbon::createFromFormat('m/Y', $valor)->startOfMonth()->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}$/', $valor)) {
            return Carbon::createFromFormat('Y-m', $valor)->startOfMonth()->toDateString();
        }

        $data = $this->normalizarDataEntrada($valor);
        return $data ? Carbon::parse($data)->startOfMonth()->toDateString() : null;
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
        if (str_contains($keys, 'data_vencimento') && (str_contains($keys, 'fornecedor') || str_contains($keys, 'codigo_barras'))) return 'criar_despesa';
        if (str_contains($keys, 'data_vencimento') && (str_contains($keys, 'cliente') || str_contains($keys, 'valor_original') || str_contains($keys, 'valor'))) return 'criar_conta_receber';
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
        if (in_array($acao, ['criar_cliente', 'cliente', 'criar_despesa', 'despesa', 'criar_conta_pagar', 'conta_pagar', 'criar_conta_receber', 'titulo_receber', 'criar_titulo_receber'])) {
            return (int)($stats['criados'] ?? 0) > 0;
        }

        $importados = (int)($stats['novos'] ?? 0) + (int)($stats['atualizados'] ?? 0);
        return $importados > 0;
    }

    /**
     * Normaliza o retorno do provider de IA para garantir que o front receba
     * dados estruturados compatíveis com os fluxos de confirmação.
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

    private function normalizarRespostaIa($body): array
    {
        $normalizado = $this->normalizarRespostaN8n($body);

        if (!empty($normalizado['dados_estruturados'])) {
            return $normalizado;
        }

        if (!is_array($body)) {
            return $normalizado;
        }

        $acao = $normalizado['acao_sugerida'] ?? ($body['acao_sugerida'] ?? null);
        if (!is_string($acao) || !in_array($acao, [
            'criar_cliente',
            'criar_despesa',
            'criar_conta_pagar',
            'criar_conta_receber',
            'criar_titulo_receber',
            'gerar_fatura',
            'criar_fatura',
        ], true)) {
            return $normalizado;
        }

        $registros = $this->extrairDadosParaProcessamento(
            $body['dados_estruturados'] ?? ($body['dados'] ?? $body)
        );

        if (empty($registros)) {
            return $normalizado;
        }

        $normalizado['dados_estruturados'] = $this->montarPayloadAcaoDireta($acao, $registros, $body);
        $normalizado['acao_sugerida'] = $acao;
        $normalizado['mensagem'] ??= "📥 Preparei " . count($registros) . " registro(s) para confirmação.";

        return $normalizado;
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

    private function montarPayloadAcaoDireta(string $acao, array $registros, array $body = []): array
    {
        $tipo = match ($acao) {
            'criar_cliente' => 'cliente',
            'criar_despesa', 'criar_conta_pagar' => 'despesa',
            'criar_conta_receber', 'criar_titulo_receber' => 'titulo_receber',
            'gerar_fatura', 'criar_fatura' => 'fatura',
            default => 'dados',
        };

        $colunas = in_array($acao, ['gerar_fatura', 'criar_fatura'], true)
            ? $this->montarColunasFaturas($registros)
            : $this->montarColunasPreview($registros);

        $metadata = [
            'fonte' => 'langchain',
            'acao' => $acao,
        ];

        if (in_array($acao, ['gerar_fatura', 'criar_fatura'], true)) {
            $metadata['preview_layout'] = 'fatura';
            $metadata['summary'] = $this->montarResumoPreviewFaturas($registros);
        }

        return [
            'sucesso' => true,
            'tipo' => $tipo,
            'acao_sugerida' => $acao,
            'dados_mapeados' => array_values($registros),
            'colunas' => $colunas,
            'total_registros' => count($registros),
            'confianca' => is_numeric($body['confianca'] ?? null) ? (float) $body['confianca'] : null,
            'metadata' => $metadata,
            'raw_output' => $body,
        ];
    }

    private function montarColunasPreview(array $registros): array
    {
        $primeiro = $registros[0] ?? [];
        if (!is_array($primeiro)) {
            return [];
        }

        return array_map(function ($key) {
            return [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
            ];
        }, array_keys($primeiro));
    }

    private function montarColunasFaturas(array $registros): array
    {
        $primeiro = $registros[0] ?? [];
        if (!is_array($primeiro)) {
            return [];
        }

        $preferidas = [
            'cliente' => 'Cliente',
            'cliente_cnpj' => 'CNPJ',
            'periodo_referencia' => 'Período',
            'data_emissao' => 'Emissão',
            'data_vencimento' => 'Vencimento',
            'quantidade_itens' => 'Itens',
            'valor_total' => 'Valor Total',
            'unidade' => 'Unidade',
            'funcionarios_resumo' => 'Funcionários',
            'exames_resumo' => 'Exames',
            'cliente_status_resumo' => 'Situação do Cliente',
            'status' => 'Status',
            'observacoes' => 'Observações',
        ];

        $colunas = [];
        foreach ($preferidas as $key => $label) {
            if (array_key_exists($key, $primeiro)) {
                $colunas[] = [
                    'key' => $key,
                    'label' => $label,
                ];
            }
        }

        foreach (array_keys($primeiro) as $key) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            if (collect($colunas)->contains(fn ($coluna) => $coluna['key'] === $key)) {
                continue;
            }

            $colunas[] = [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
            ];
        }

        return $colunas;
    }

    private function montarResumoPreviewFaturas(array $registros): array
    {
        $valorTotal = 0.0;
        $itensTotal = 0;
        $periodos = [];
        $vencimentos = [];

        foreach ($registros as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $valorTotal += (float) ($registro['valor_total'] ?? 0);
            $itensTotal += (int) ($registro['quantidade_itens'] ?? 0);

            if (!empty($registro['periodo_referencia'])) {
                $periodos[] = (string) $registro['periodo_referencia'];
            }

            if (!empty($registro['data_vencimento'])) {
                $vencimentos[] = (string) $registro['data_vencimento'];
            }
        }

        return [
            'total_faturas' => count($registros),
            'total_itens' => $itensTotal,
            'valor_total' => round($valorTotal, 2),
            'periodos' => array_values(array_unique($periodos)),
            'vencimentos' => array_values(array_unique($vencimentos)),
        ];
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
