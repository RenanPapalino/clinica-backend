<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAiAudioTranscriptionService
{
    public function transcrever(string $caminhoArquivo, string $nomeOriginal = 'audio.webm'): array
    {
        $apiKey = (string) config('services.openai.key');

        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY não configurada para transcrição de áudio.');
        }

        $response = Http::withToken($apiKey)
            ->timeout((int) config('services.openai.timeout', 120))
            ->attach(
                'file',
                file_get_contents($caminhoArquivo),
                $nomeOriginal
            )
            ->post((string) config('services.openai.audio_transcriptions_url'), [
                'model' => (string) config('services.openai.transcription_model', 'whisper-1'),
                'response_format' => 'verbose_json',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Falha ao transcrever o áudio enviado.');
        }

        $payload = $response->json();
        $texto = trim((string) ($payload['text'] ?? ''));

        if ($texto === '') {
            throw new \RuntimeException('A transcrição do áudio retornou vazia.');
        }

        return [
            'status' => 'ok',
            'tipo' => 'audio',
            'transcricao' => $texto,
            'idioma' => $payload['language'] ?? null,
            'duracao_segundos' => isset($payload['duration']) ? (float) $payload['duration'] : null,
            'resumo_curto' => Str::limit($texto, 180),
        ];
    }
}
