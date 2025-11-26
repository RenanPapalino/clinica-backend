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
    public function enviarMensagem(Request $request)
    {
        $user = $request->user();
        $sessionId = $request->input('session_id', 'session_' . $user->id);
        $mensagemUsuario = $request->input('mensagem') ?? 'Envio de arquivo';

        // 1. Registra mensagem do usuário
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $mensagemUsuario,
            'session_id' => $sessionId,
        ]);

        $n8nWebhookUrl = env('N8N_WEBHOOK_CHAT_URL');
        $respostaIa = "Não consegui processar sua solicitação.";

        if ($n8nWebhookUrl) {
            try {
                // Prepara a requisição para o N8N
                $http = Http::timeout(60)
                    ->attach('metadata', json_encode([
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'session_id' => $sessionId
                    ]), 'metadata.json');

                // Se tiver arquivo, anexa
                if ($request->hasFile('arquivo')) {
                    $file = $request->file('arquivo');
                    $http->attach(
                        'arquivo', 
                        file_get_contents($file->getRealPath()), 
                        $file->getClientOriginalName()
                    );
                }

                // Envia
                $response = $http->post($n8nWebhookUrl, [
                    'message' => $mensagemUsuario
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    // --- LÓGICA DE CADASTRO AUTOMÁTICO ---
                    // Se o N8N devolver uma lista de clientes identificados, salvamos aqui
                    if (isset($data['clientes_identificados']) && is_array($data['clientes_identificados'])) {
                        $qtdSalva = $this->salvarClientesAutomaticamente($data['clientes_identificados']);
                        $respostaIa = "Recebi a planilha! ✅ {$qtdSalva} clientes foram cadastrados/atualizados com sucesso.";
                    } else {
                        $respostaIa = $data['output'] ?? $data['text'] ?? "Processado, mas sem resposta de texto.";
                    }

                } else {
                    $respostaIa = "Erro no N8N: " . $response->status();
                }
            } catch (\Exception $e) {
                Log::error("Erro Chat N8N: " . $e->getMessage());
                $respostaIa = "Ocorreu um erro técnico ao enviar o arquivo.";
            }
        }

        // 3. Salvar resposta da IA
        $chatMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $respostaIa,
            'session_id' => $sessionId,
        ]);

        return response()->json(['success' => true, 'data' => $chatMessage]);
    }

    /**
     * Função auxiliar para salvar o que vier do N8N
     */
    private function salvarClientesAutomaticamente(array $clientes)
    {
        $count = 0;
        foreach ($clientes as $c) {
            if (empty($c['razao_social'])) continue;
            
            $cnpj = isset($c['cnpj']) ? preg_replace('/\D/', '', $c['cnpj']) : null;
            
            $dados = [
                'razao_social' => mb_strtoupper($c['razao_social']),
                'email' => strtolower($c['email'] ?? ''),
                'telefone' => $c['telefone'] ?? null,
                'status' => 'ativo'
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