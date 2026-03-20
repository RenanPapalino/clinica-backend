<?php

namespace App\Actions\Rag;

use App\Models\RagChunk;
use App\Models\RagDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpsertRagDocumentAction
{
    public function execute(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $document = RagDocument::withTrashed()->firstOrNew([
                'source_system' => $payload['source_system'],
                'external_id' => $payload['external_id'],
            ]);

            if ($document->trashed()) {
                $document->restore();
            }

            $currentChecksum = $document->checksum;
            $newChecksum = $payload['checksum'] ?? null;

            if ($document->exists && $newChecksum && $currentChecksum && hash_equals($currentChecksum, $newChecksum)) {
                return [
                    'document' => $document->fresh(),
                    'status' => 'skipped',
                    'message' => 'Documento já indexado com o mesmo checksum.',
                ];
            }

            $chunks = collect($payload['chunks'] ?? [])
                ->map(function (array $chunk, int $index) {
                    return [
                        'chunk_index' => $chunk['chunk_index'] ?? $index,
                        'content' => trim((string) ($chunk['content'] ?? '')),
                        'metadata' => $chunk['metadata'] ?? [],
                    ];
                })
                ->filter(fn (array $chunk) => $chunk['content'] !== '')
                ->values();

            if ($chunks->isEmpty()) {
                throw new \InvalidArgumentException('Nenhum chunk válido foi recebido para indexação.');
            }

            $nextVersion = $document->exists ? ((int) $document->current_version + 1) : 1;

            $document->fill([
                'file_name' => $payload['file_name'],
                'file_type' => $payload['file_type'] ?? null,
                'business_context' => $payload['business_context'] ?? null,
                'context_key' => $payload['context_key'] ?? null,
                'status' => 'processing',
                'checksum' => $newChecksum,
                'external_updated_at' => $payload['external_updated_at'] ?? null,
                'metadata' => Arr::wrap($payload['metadata'] ?? []),
            ]);
            $document->save();

            RagChunk::where('rag_document_id', $document->id)
                ->where('version', $nextVersion)
                ->delete();

            foreach ($chunks as $chunk) {
                RagChunk::create([
                    'rag_document_id' => $document->id,
                    'version' => $nextVersion,
                    'chunk_index' => $chunk['chunk_index'],
                    'content' => $chunk['content'],
                    'content_hash' => hash('sha256', $chunk['content']),
                    'metadata' => $chunk['metadata'],
                    'is_active' => false,
                ]);
            }

            RagChunk::where('rag_document_id', $document->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            RagChunk::where('rag_document_id', $document->id)
                ->where('version', $nextVersion)
                ->update(['is_active' => true]);

            $document->update([
                'status' => 'active',
                'current_version' => $nextVersion,
                'chunks_count' => $chunks->count(),
                'last_indexed_at' => now(),
            ]);

            return [
                'document' => $document->fresh('activeChunks'),
                'status' => $document->wasRecentlyCreated ? 'created' : 'updated',
                'message' => 'Documento indexado com sucesso.',
            ];
        });
    }
}
