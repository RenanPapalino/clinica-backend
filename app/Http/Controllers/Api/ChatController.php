<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Envia mensagem do usuário para o N8N e registra o chat
     */
    public function enviarMensagem(Request $request)
    {
        $user = $request->user();
        $sessionId = $request->input('session_id', 'session_' . $user->id);
        $mensagemUsuario = trim($request->input('mensagem', ''));

        if ($mensagemUsuario === '') {
            return response()->json([
                'success' => false,
                'message' => 'Mensagem não pode ser vazia.'
            ], 422);
        }

        // 1. Registra mensagem do usuário
        ChatMessage::create([
            'user_id'    => $user->id,
            'role'       => 'user',
            'content'    => $mensagemUsuario,
            'session_id' => $sessionId,
        ]);

        $n8nWebhookUrl = env('N8N_WEBHOOK_CHAT_URL');
        $respostaIa = 'Não consegui processar sua solicitação.';

        if ($n8nWebhookUrl) {
            try {
                // Envia APENAS TEXTO + METADADOS (sem arquivo)
                $payload = [
                    'message'  => $mensagemUsuario,
                    'metadata' => [
                        'user_id'    => $user->id,
                        'user_name'  => $user->name,
                        'session_id' => $sessionId,
                    ],
                ];

                $response = Http::timeout(60)->post($n8nWebhookUrl, $payload);

                if ($response->successful()) {
                    $data = $response->json();

                    // --- LÓGICA DE CADASTRO AUTOMÁTICO ---
                    // Caso o N8N envie clientes_identificados em formato de objeto normal
                    if (is_array($data)
                        && isset($data['clientes_identificados'])
                        && is_array($data['clientes_identificados'])
                    ) {
                        $qtdSalva  = $this->salvarClientesAutomaticamente($data['clientes_identificados']);
                        $respostaIa = "Recebi a planilha! ✅ {$qtdSalva} clientes foram cadastrados/atualizados com sucesso.";
                    } else {
                        $mensagemN8n = null;

                        // 1) Caso o N8N retorne uma LISTA: [ { "output": "..." } ]
                        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                            $primeiro   = $data[0];
                            $mensagemN8n = $primeiro['output']
                                ?? $primeiro['text']
                                ?? null;

                        // 2) Caso retorne um OBJETO: { "output": "..." }
                        } elseif (is_array($data)) {
                            $mensagemN8n = $data['output']
                                ?? $data['text']
                                ?? null;
                        }

                        // 3) Caso seja string pura
                        if (is_string($data)) {
                            $mensagemN8n = $data;
                        }

                        $respostaIa = $mensagemN8n ?? 'Processado, mas sem resposta de texto.';
                    }
                } else {
                    $respostaIa = 'Erro no N8N: ' . $response->status();
                }
            } catch (\Throwable $e) {
                Log::error('Erro ao chamar N8N no ChatController: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
                $respostaIa = 'Ocorreu um erro técnico ao processar sua mensagem.';
            }
        }

        // 3. Salvar resposta da IA
        $chatMessage = ChatMessage::create([
            'user_id'    => $user->id,
            'role'       => 'assistant',
            'content'    => $respostaIa,
            'session_id' => $sessionId,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $chatMessage,
        ]);
    }

    /**
     * Função auxiliar para salvar o que vier do N8N
     */
    private function salvarClientesAutomaticamente(array $clientes)
    {
        $count = 0;

        foreach ($clientes as $c) {
            if (empty($c['razao_social'])) {
                continue;
            }

            $cnpj = isset($c['cnpj'])
                ? preg_replace('/\D/', '', $c['cnpj'])
                : null;

            $dados = [
                'razao_social' => mb_strtoupper($c['razao_social']),
                'email'        => strtolower($c['email'] ?? ''),
                'telefone'     => $c['telefone'] ?? null,
                'status'       => 'ativo',
            ];

            if ($cnpj) {
                Cliente::updateOrCreate(['cnpj' => $cnpj], $dados);
                $count++;
            } elseif (!Cliente::where('razao_social', $dados['razao_social'])->exists()) {
                // Se não tem CNPJ, cria só se não existir nome igual
                Cliente::create(array_merge($dados, ['cnpj' => null]));
                $count++;
            }
        }

        return $count;
    }
}
