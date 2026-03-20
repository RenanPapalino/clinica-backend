<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ChatAttachmentProcessingService
{
    public function __construct(
        protected OpenAiImageVisionService $imageVisionService,
        protected OpenAiAudioTranscriptionService $audioTranscriptionService,
    ) {
    }

    public function processar(?UploadedFile $arquivo, string $mensagem = ''): ?array
    {
        if (!$arquivo || !$arquivo->isValid()) {
            return null;
        }

        $tipo = $this->inferirTipo(
            $arquivo->getClientMimeType(),
            $arquivo->getClientOriginalExtension()
        );

        if (!$tipo) {
            return null;
        }

        try {
            return match ($tipo) {
                'image' => $this->imageVisionService->analisar(
                    $arquivo->getRealPath(),
                    (string) $arquivo->getClientMimeType(),
                    $mensagem
                ),
                'audio' => $this->audioTranscriptionService->transcrever(
                    $arquivo->getRealPath(),
                    $arquivo->getClientOriginalName()
                ),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('Falha ao processar anexo do chat', [
                'arquivo' => $arquivo->getClientOriginalName(),
                'mime_type' => $arquivo->getClientMimeType(),
                'tipo' => $tipo,
                'erro' => $e->getMessage(),
            ]);

            return [
                'status' => 'erro',
                'tipo' => $tipo,
                'mensagem' => $e->getMessage(),
            ];
        }
    }

    public function inferirTipo(?string $mimeType, ?string $extensao = null): ?string
    {
        $mime = strtolower((string) $mimeType);
        $ext = strtolower((string) $extensao);

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic'], true)) {
            return 'image';
        }

        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'], true)) {
            return 'audio';
        }

        return null;
    }

    public function resumoMetadata(?array $anexoProcessado): ?array
    {
        if (!$anexoProcessado) {
            return null;
        }

        return array_filter([
            'status' => $anexoProcessado['status'] ?? null,
            'tipo' => $anexoProcessado['tipo'] ?? null,
            'resumo' => $anexoProcessado['resumo_curto'] ?? $anexoProcessado['resumo'] ?? null,
            'idioma' => $anexoProcessado['idioma'] ?? null,
            'duracao_segundos' => $anexoProcessado['duracao_segundos'] ?? null,
            'mensagem' => $anexoProcessado['mensagem'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
