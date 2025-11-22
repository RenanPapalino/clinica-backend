<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    use ApiResponseTrait;

    public function enviarMensagem(Request $request)
    {
        $request->validate([
            'mensagem' => 'required|string',
            'session_id' => 'nullable|string'
        ]);

        $mensagemUsuario = $request->input('mensagem');
        $user = $request->user(); // Usuário autenticado
        $sessionId = $request->input('session_id', 'session_' . $user->id);

        // 1. Salvar mensagem do usuário no banco
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $mensagemUsuario,
            'session_id' => $sessionId,
        ]);

        // 2. Enviar para o Agente Orquestrador no n8n
        // URL do seu webhook do n8n (ajuste conforme seu setup)
        $n8nWebhookUrl = env('N8N_CHAT_WEBHOOK_URL', 'http://localhost:5678/webhook/chat-agent');

        try {
            // Enviamos contexto do usuário para o n8n
            $response = Http::timeout(30)->post($n8nWebhookUrl, [
                'message' => $mensagemUsuario,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'session_id' => $sessionId,
                'context' => 'financeiro' 
            ]);

            if ($response->successful()) {
                $data = $response->json();
                // Espera que o n8n retorne: { "output": "Texto da resposta" }
                $respostaIa = $data['output'] ?? 'Desculpe, não consegui processar sua solicitação no momento.';
            } else {
                $respostaIa = 'Erro de comunicação com o assistente inteligente.';
            }

        } catch (\Exception $e) {
            $respostaIa = 'O assistente está indisponível temporariamente.';
             \Log::error("Erro n8n: " . $e->getMessage());
        }

        // 3. Salvar resposta da IA
        $chatMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $respostaIa,
            'session_id' => $sessionId,
        ]);

        return $this->successResponse([
            'id' => (string) $chatMessage->id,
            'role' => 'assistant',
            'content' => $respostaIa,
            'timestamp' => $chatMessage->created_at->toISOString(),
        ]);
    }

    public function historico(Request $request)
    {
        $user = $request->user();
        $messages = ChatMessage::where('user_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->take(50)
            ->get();

        return $this->successResponse($messages);
    }
}