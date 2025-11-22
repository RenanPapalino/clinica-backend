<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function enviarMensagem(Request $request)
    {
        $request->validate([
            'mensagem' => 'required|string',
            'session_id' => 'nullable|string'
        ]);

        $mensagemUsuario = $request->input('mensagem');
        $user = $request->user();
        $sessionId = $request->input('session_id', 'session_' . $user->id);

        // 1. Salvar mensagem do usuário
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $mensagemUsuario,
            'session_id' => $sessionId,
        ]);

        // 2. Enviar para IA (n8n)
        $n8nWebhookUrl = env('N8N_CHAT_WEBHOOK_URL');
        $respostaIa = "Desculpe, o assistente está indisponível no momento.";

        if ($n8nWebhookUrl) {
            try {
                $response = Http::timeout(30)->post($n8nWebhookUrl, [
                    'message' => $mensagemUsuario,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'session_id' => $sessionId,
                    'context' => 'financeiro' 
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    // Aceita 'output', 'text' ou 'message' como resposta do n8n
                    $respostaIa = $data['output'] ?? $data['text'] ?? $data['message'] ?? 'Recebi, mas não entendi a resposta da IA.';
                } else {
                    Log::error("Erro n8n: Status " . $response->status());
                    $respostaIa = "Erro de comunicação com a IA (Código: " . $response->status() . ")";
                }
            } catch (\Exception $e) {
                Log::error("Erro n8n Exception: " . $e->getMessage());
                $respostaIa = "O assistente está demorando muito para responder.";
            }
        } else {
            // Modo Simulação (se não tiver n8n configurado no .env)
            $respostaIa = "Modo Demo: Recebi sua mensagem '{$mensagemUsuario}'. Configure o N8N_CHAT_WEBHOOK_URL no .env para ativar a IA real.";
        }

        // 3. Salvar resposta da IA
        $chatMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $respostaIa,
            'session_id' => 'session_' . $user->id, // Corrigido para salvar na mesma sessão
        ]);

        return response()->json([
            'success' => true,
            'data' => $chatMessage // Retorna o objeto completo para o front
        ]);
    }

    public function historico(Request $request)
    {
        $user = $request->user();
        // Ordena por created_at ASC para o chat exibir na ordem correta (antigas em cima)
        $messages = ChatMessage::where('user_id', $user->id)
            ->orderBy('created_at', 'asc') 
            ->take(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }
}