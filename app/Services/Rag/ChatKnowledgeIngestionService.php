<?php

namespace App\Services\Rag;

use App\Actions\Rag\UpsertRagDocumentAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ChatKnowledgeIngestionService
{
    public function __construct(
        protected ChatKnowledgeTextExtractorService $textExtractor,
        protected UpsertRagDocumentAction $upsertRagDocumentAction,
    ) {
    }

    public function ingestChatUpload(
        UploadedFile $file,
        array $context = [],
        ?array $processedAttachment = null,
        ?array $driveIngestion = null,
    ): array {
        $extracted = $this->textExtractor->extract($file, $processedAttachment);
        $content = trim((string) ($extracted['content'] ?? ''));

        if ($content === '') {
            return [
                'success' => false,
                'status' => !empty($extracted['pending_async']) && ($driveIngestion['success'] ?? false) ? 'pending_async' : 'skipped',
                'message' => $extracted['message'] ?? 'O conteúdo do arquivo ainda não pôde ser indexado.',
                'provider' => 'rag_mysql',
                'source_system' => $this->resolveSourceSystem($driveIngestion),
                'external_id' => $this->resolveExternalId($file, $context, $driveIngestion),
            ];
        }

        $payload = [
            'source_system' => $this->resolveSourceSystem($driveIngestion),
            'external_id' => $this->resolveExternalId($file, $context, $driveIngestion),
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientMimeType() ?: $file->getClientOriginalExtension(),
            'business_context' => 'chat_upload',
            'context_key' => $this->resolveContextKey($context),
            'checksum' => $this->resolveChecksum($file, $content),
            'external_updated_at' => now()->toISOString(),
            'metadata' => array_filter([
                'source_scope' => 'chat_upload',
                'upload_source' => 'chatbot',
                'user_id' => $context['user_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'tipo_processamento' => $context['tipo_processamento'] ?? null,
                'mime_type' => $file->getClientMimeType(),
                'extension' => $file->getClientOriginalExtension(),
                'drive_file_id' => $driveIngestion['file_id'] ?? null,
                'drive_folder_id' => $driveIngestion['folder_id'] ?? null,
                'drive_web_view_link' => $driveIngestion['web_view_link'] ?? null,
                'extraction_mode' => $extracted['mode'] ?? null,
                'processed_attachment' => $processedAttachment,
            ], static fn ($value) => $value !== null && $value !== ''),
            'chunks' => $this->chunkContent($content),
        ];

        $result = $this->upsertRagDocumentAction->execute($payload);

        return [
            'success' => true,
            'status' => $result['status'],
            'message' => $result['message'],
            'provider' => 'rag_mysql',
            'document_id' => $result['document']->id,
            'source_system' => $payload['source_system'],
            'external_id' => $payload['external_id'],
            'context_key' => $payload['context_key'],
            'business_context' => $payload['business_context'],
            'chunks_count' => $result['document']->chunks_count,
            'extraction_mode' => $extracted['mode'] ?? null,
        ];
    }

    private function resolveSourceSystem(?array $driveIngestion): string
    {
        return !empty($driveIngestion['success']) && !empty($driveIngestion['file_id'])
            ? 'chatbot_upload'
            : 'chatbot_upload';
    }

    private function resolveExternalId(UploadedFile $file, array $context, ?array $driveIngestion): string
    {
        if (!empty($driveIngestion['file_id'])) {
            return 'chat-upload:' . $driveIngestion['file_id'];
        }

        return 'chat-upload:' . ($context['user_id'] ?? 'anon') . ':' . ($context['session_id'] ?? 'sem-sessao') . ':' . sha1(
            $file->getClientOriginalName() . '|' . (string) @filesize($file->getRealPath()) . '|' . (string) @filemtime($file->getRealPath())
        );
    }

    private function resolveContextKey(array $context): string
    {
        return 'chat_user_' . ($context['user_id'] ?? 'anon');
    }

    private function resolveChecksum(UploadedFile $file, string $content): string
    {
        $path = $file->getRealPath();
        if (is_string($path) && is_file($path)) {
            $checksum = @hash_file('sha256', $path);
            if (is_string($checksum) && $checksum !== '') {
                return $checksum;
            }
        }

        return hash('sha256', $content);
    }

    private function chunkContent(string $content): array
    {
        $maxLength = max(500, (int) config('chatbot.rag.max_chunk_length', 8000));
        $paragraphs = preg_split("/\R{2,}/u", $content) ?: [];
        $chunks = [];
        $buffer = '';
        $index = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($buffer === '') {
                $buffer = $paragraph;
                continue;
            }

            $candidate = $buffer . "\n\n" . $paragraph;
            if (mb_strlen($candidate) <= $maxLength) {
                $buffer = $candidate;
                continue;
            }

            $chunks[] = [
                'chunk_index' => $index++,
                'content' => $buffer,
                'metadata' => [
                    'source' => 'chat_upload',
                ],
            ];

            if (mb_strlen($paragraph) <= $maxLength) {
                $buffer = $paragraph;
                continue;
            }

            foreach ($this->splitLongParagraph($paragraph, $maxLength) as $fragment) {
                $chunks[] = [
                    'chunk_index' => $index++,
                    'content' => $fragment,
                    'metadata' => [
                        'source' => 'chat_upload',
                    ],
                ];
            }

            $buffer = '';
        }

        if ($buffer !== '') {
            $chunks[] = [
                'chunk_index' => $index,
                'content' => $buffer,
                'metadata' => [
                    'source' => 'chat_upload',
                ],
            ];
        }

        return $chunks;
    }

    private function splitLongParagraph(string $paragraph, int $maxLength): array
    {
        $words = preg_split('/\s+/u', $paragraph) ?: [];
        $fragments = [];
        $buffer = '';

        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }

            $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
            if (mb_strlen($candidate) <= $maxLength) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $fragments[] = $buffer;
            }

            $buffer = Str::limit($word, $maxLength, '');
        }

        if ($buffer !== '') {
            $fragments[] = $buffer;
        }

        return $fragments;
    }
}
