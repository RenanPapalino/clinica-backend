<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Enviar mensagem para o chatbot (com ou sem arquivo)
     */
    public function enviarMensagem(Request $request)
    {
        Log::info('=== CHAT: Nova mensagem ===');

        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado.'
                ], 401);
            }

            $sessionId = $request->input('session_id', 'session_' . $user->id);
            $mensagem = trim($request->input('mensagem', ''));
            $arquivo = $request->file('arquivo');

            // Validação: precisa ter mensagem OU arquivo
            if ($mensagem === '' && !$arquivo) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Envie uma mensagem ou anexe um arquivo.'
                ], 422);
            }

            // Preparar conteúdo e metadata
            $conteudoLog = $mensagem;
            $metadata = null;

            if ($arquivo) {
                // Validar arquivo
                $maxSize = 10 * 1024 * 1024; // 10MB
                if ($arquivo->getSize() > $maxSize) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Arquivo muito grande. Máximo: 10MB.'
                    ], 422);
                }

                $extensoesPermitidas = ['csv', 'xlsx', 'xls', 'pdf', 'txt', 'xml', 'json'];
                $extensao = strtolower($arquivo->getClientOriginalExtension());
                
                if (!in_array($extensao, $extensoesPermitidas)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de arquivo não permitido. Use: ' . implode(', ', $extensoesPermitidas)
                    ], 422);
                }

                $nomeArquivo = $arquivo->getClientOriginalName();
                $tamanhoArquivo = $arquivo->getSize();
                $tipoArquivo = $arquivo->getClientMimeType();

                $anexoInfo = "[📎 Arquivo: {$nomeArquivo}]";
                $conteudoLog = $mensagem ? "{$mensagem}\n\n{$anexoInfo}" : $anexoInfo;
                
                $metadata = [
                    'file_name' => $nomeArquivo,
                    'file_size' => $tamanhoArquivo,
                    'file_type' => $tipoArquivo
                ];

                Log::info('Arquivo recebido:', $metadata);
            }

            // 1. Salvar mensagem do usuário
            ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'user',
                'content'    => $conteudoLog,
                'session_id' => $sessionId,
                'metadata'   => $metadata
            ]);

            // 2. Enviar para N8N (rota diferente se tem arquivo)
            if ($arquivo) {
                // ROTA DE ARQUIVO
                $respostaIa = $this->enviarArquivoParaN8n($mensagem, $user, $sessionId, $arquivo);
            } else {
                // ROTA DE CHAT (só texto)
                $respostaIa = $this->enviarMensagemParaN8n($mensagem, $user, $sessionId);
            }

            // 3. Salvar resposta da IA
            $chatMessage = ChatMessage::create([
                'user_id'    => $user->id,
                'role'       => 'assistant',
                'content'    => $respostaIa,
                'session_id' => $sessionId,
                'metadata'   => null
            ]);

            Log::info('Resposta salva:', ['id' => $chatMessage->id]);

            // 4. Retornar para o frontend
            return response()->json([
                'success' => true,
                'data'    => [
                    'id'         => $chatMessage->id,
                    'role'       => $chatMessage->role,
                    'content'    => $chatMessage->content,
                    'created_at' => $chatMessage->created_at->toISOString(),
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Erro ChatController:', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Erro interno do servidor.'
            ], 500);
        }
    }

    /**
     * Enviar MENSAGEM DE TEXTO para N8N (sem arquivo)
     */
    private function enviarMensagemParaN8n(string $mensagem, $user, string $sessionId): string
    {
        $webhookUrl = env('N8N_WEBHOOK_CHAT_URL');
        
        if (!$webhookUrl) {
            Log::warning('N8N_WEBHOOK_CHAT_URL não configurada');
            return '⚠️ Serviço de IA não configurado.';
        }

        try {
            Log::info("Enviando TEXTO para N8N: {$webhookUrl}");

            $response = Http::timeout(120)
                ->acceptJson()
                ->post($webhookUrl, [
                    'message'    => $mensagem,
                    'user_id'    => $user->id,
                    'user_name'  => $user->name,
                    'session_id' => $sessionId,
                ]);

            Log::info('N8N Chat Status: ' . $response->status());

            if (!$response->successful()) {
                Log::error('Erro N8N Chat: ' . $response->body());
                return "❌ Erro na comunicação com a IA (HTTP {$response->status()}).";
            }

            return $this->extrairResposta($response->json());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Timeout N8N Chat: ' . $e->getMessage());
            return '❌ Tempo limite excedido. Tente novamente.';
        } catch (\Exception $e) {
            Log::error('Erro conexão N8N Chat: ' . $e->getMessage());
            return '❌ Erro de conexão com o serviço de IA.';
        }
    }

    /**
     * Enviar ARQUIVO para N8N (rota separada)
     */
    private function enviarArquivoParaN8n(string $mensagem, $user, string $sessionId, $arquivo): string
    {
        $webhookUrl = env('N8N_WEBHOOK_ARQUIVO_URL');
        
        if (!$webhookUrl) {
            Log::warning('N8N_WEBHOOK_ARQUIVO_URL não configurada');
            return '⚠️ Serviço de upload de arquivos não configurado.';
        }

        try {
            $caminhoReal = $arquivo->getRealPath();
            
            if (!file_exists($caminhoReal) || !is_readable($caminhoReal)) {
                Log::error("Arquivo temporário não acessível: {$caminhoReal}");
                return '❌ Erro ao processar o arquivo enviado.';
            }

            // Preparar dados do formulário multipart
            $postFields = [
                'message'    => $mensagem,
                'user_id'    => (string) $user->id,
                'user_name'  => $user->name,
                'session_id' => $sessionId,
                'file_name'  => $arquivo->getClientOriginalName(),
                'file_type'  => $arquivo->getClientMimeType(),
                'file_size'  => (string) $arquivo->getSize(),
                'file'       => new \CURLFile(
                    $caminhoReal,
                    $arquivo->getClientMimeType(),
                    $arquivo->getClientOriginalName()
                ),
            ];

            Log::info('Enviando ARQUIVO para N8N:', [
                'url'       => $webhookUrl,
                'file_name' => $arquivo->getClientOriginalName(),
                'file_size' => $arquivo->getSize(),
                'file_type' => $arquivo->getClientMimeType()
            ]);

            // Inicializar cURL
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL            => $webhookUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);

            Log::info('Resposta N8N Arquivo:', [
                'http_code' => $httpCode,
                'response'  => substr($response, 0, 500),
                'error'     => $error
            ]);

            if ($error) {
                Log::error("Erro cURL: {$error}");
                return "❌ Erro de conexão: {$error}";
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                return "❌ Erro ao enviar arquivo (HTTP {$httpCode}).";
            }

            // Decodificar resposta JSON
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $response ?: 'Arquivo recebido pelo servidor.';
            }

            return $this->extrairResposta($data);

        } catch (\Exception $e) {
            Log::error('Erro enviarArquivoParaN8n: ' . $e->getMessage());
            return '❌ Erro ao processar arquivo.';
        }
    }

    /**
     * Extrair texto da resposta do N8N
     */
    private function extrairResposta($data): string
    {
        Log::info('Resposta N8N:', ['data' => $data]);

        if (empty($data)) {
            return 'Resposta vazia do servidor.';
        }

        // Formato: [{"output": "texto"}]
        if (is_array($data) && isset($data[0]['output'])) {
            return $data[0]['output'];
        }

        // Formato: {"output": "texto"}
        if (is_array($data) && isset($data['output'])) {
            return $data['output'];
        }

        // Formato: {"text": "texto"}
        if (is_array($data) && isset($data['text'])) {
            return $data['text'];
        }

        // Formato: {"response": "texto"}
        if (is_array($data) && isset($data['response'])) {
            return $data['response'];
        }

        // Formato: {"message": "texto"}
        if (is_array($data) && isset($data['message'])) {
            return $data['message'];
        }

        // Formato: [{"text": "texto"}]
        if (is_array($data) && isset($data[0]['text'])) {
            return $data[0]['text'];
        }

        // String direta
        if (is_string($data)) {
            return $data;
        }

        // Fallback: JSON formatado
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Buscar histórico de mensagens
     */
    public function historico(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado.'
                ], 401);
            }

            $sessionId = $request->input('session_id', 'session_' . $user->id);

            $mensagens = ChatMessage::where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->orderBy('created_at', 'asc')
                ->limit(50)
                ->get(['id', 'role', 'content', 'created_at', 'metadata']);

            return response()->json([
                'success' => true,
                'data'    => $mensagens,
            ]);

        } catch (\Throwable $e) {
            Log::error('Erro histórico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar histórico.'
            ], 500);
        }
    }

    /**
     * Limpar histórico
     */
    public function limparHistorico(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado.'
                ], 401);
            }

            $sessionId = $request->input('session_id', 'session_' . $user->id);

            $deletados = ChatMessage::where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "{$deletados} mensagens removidas.",
            ]);

        } catch (\Throwable $e) {
            Log::error('Erro limpar histórico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao limpar histórico.'
            ], 500);
        }
    }
}
