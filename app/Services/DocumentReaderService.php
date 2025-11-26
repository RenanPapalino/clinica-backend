<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DocumentReaderService
{
    public function lerDocumento($caminhoArquivo)
    {
        $apiKey = config('services.openai.key'); // Configure no .env
        $imagemBase64 = base64_encode(file_get_contents($caminhoArquivo));

        $prompt = "
            Analise esta imagem de um documento financeiro (Boleto, Nota Fiscal ou Recibo).
            Extraia os seguintes dados em formato JSON estrito:
            - valor_total (float)
            - data_vencimento (YYYY-MM-DD)
            - data_emissao (YYYY-MM-DD)
            - descricao (resumo curto do que é)
            - codigo_barras (linha digitável se houver)
            - cnpj_fornecedor (apenas números)
            - nome_fornecedor
            
            Se não encontrar algum campo, retorne null. Não invente dados.
        ";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:image/jpeg;base64,{$imagemBase64}"
                            ]
                        ]
                    ]
                ]
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 500
        ]);

        if ($response->failed()) {
            Log::error('Erro OpenAI: ' . $response->body());
            throw new \Exception('Falha na leitura inteligente do documento.');
        }

        return $response->json()['choices'][0]['message']['content'];
    }
}