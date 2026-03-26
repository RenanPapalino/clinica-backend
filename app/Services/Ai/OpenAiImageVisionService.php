<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAiImageVisionService
{
    public function analisar(string $caminhoArquivo, string $mimeType, string $mensagem = ''): array
    {
        $apiKey = (string) config('services.openai.key');

        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY não configurada para análise de imagem.');
        }

        $imagemBase64 = base64_encode(file_get_contents($caminhoArquivo));
        $promptUsuario = trim($mensagem);
        $prompt = trim(implode("\n", array_filter([
            'Analise a imagem enviada pelo usuário para a MedIA financeira e operacional.',
            'Retorne JSON estrito com os campos: resumo, texto_extraido, itens_relevantes, proxima_acao_sugerida.',
            'texto_extraido deve conter o texto visível mais importante na imagem.',
            'itens_relevantes deve ser um array de strings curtas com os pontos mais úteis.',
            'Não invente dados que não estejam visíveis.',
            $promptUsuario !== '' ? 'Considere também a intenção escrita pelo usuário: ' . $promptUsuario : null,
        ])));

        $response = Http::withToken($apiKey)
            ->timeout((int) config('services.openai.timeout', 120))
            ->post((string) config('services.openai.chat_completions_url'), [
                'model' => (string) config('services.openai.vision_model', 'gpt-4.1-mini'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$imagemBase64}",
                                ],
                            ],
                        ],
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 900,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Falha ao analisar a imagem enviada.');
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;

        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta inválida ao analisar a imagem.');
        }

        return [
            'status' => 'ok',
            'tipo' => 'image',
            'resumo' => trim((string) ($decoded['resumo'] ?? '')),
            'texto_extraido' => trim((string) ($decoded['texto_extraido'] ?? '')),
            'itens_relevantes' => array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), (array) ($decoded['itens_relevantes'] ?? [])),
                static fn ($item) => $item !== ''
            )),
            'proxima_acao_sugerida' => trim((string) ($decoded['proxima_acao_sugerida'] ?? '')),
            'resumo_curto' => Str::limit(trim((string) ($decoded['resumo'] ?? '')), 180),
        ];
    }
}
