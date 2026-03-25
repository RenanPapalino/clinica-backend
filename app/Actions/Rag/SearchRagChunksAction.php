<?php

namespace App\Actions\Rag;

use App\Models\RagChunk;
use Illuminate\Support\Str;

class SearchRagChunksAction
{
    public function execute(string $query, array $filters = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = collect(preg_split('/\s+/', Str::lower($query)) ?: [])
            ->map(fn (string $term) => trim($term))
            ->filter(fn (string $term) => mb_strlen($term) >= 2)
            ->values();

        $builder = RagChunk::query()
            ->with('document')
            ->where('is_active', true)
            ->whereHas('document', function ($documentQuery) use ($filters) {
                $documentQuery->ativos();

                if (!empty($filters['business_context'])) {
                    $documentQuery->where('business_context', $filters['business_context']);
                }

                if (!empty($filters['context_key'])) {
                    $documentQuery->where('context_key', $filters['context_key']);
                    return;
                }

                $userContextKeys = collect($filters['user_context_keys'] ?? [])
                    ->map(fn ($key) => trim((string) $key))
                    ->filter()
                    ->values();

                if ($userContextKeys->isNotEmpty()) {
                    $documentQuery->where(function ($scopeQuery) use ($userContextKeys) {
                        $scopeQuery
                            ->where(function ($globalQuery) {
                                $globalQuery
                                    ->where('business_context', '!=', 'chat_upload')
                                    ->orWhereNull('business_context');
                            })
                            ->orWhere(function ($userUploadQuery) use ($userContextKeys) {
                                $userUploadQuery
                                    ->where('business_context', 'chat_upload')
                                    ->whereIn('context_key', $userContextKeys->all());
                            });
                    });
                }
            });

        $builder->where(function ($chunkQuery) use ($terms, $query) {
            $chunkQuery->where('content', 'like', '%' . $query . '%');

            foreach ($terms as $term) {
                $chunkQuery->orWhere('content', 'like', '%' . $term . '%');
            }
        });

        $limit = min((int) ($filters['limit'] ?? config('chatbot.rag.search_limit', 8)), 20);

        $candidates = $builder->limit(100)->get();

        return $candidates
            ->map(function (RagChunk $chunk) use ($query, $terms) {
                $content = Str::lower($chunk->content);
                $score = str_contains($content, Str::lower($query)) ? 10 : 0;

                foreach ($terms as $term) {
                    $score += substr_count($content, $term);
                }

                return [
                    'score' => $score,
                    'chunk_id' => $chunk->id,
                    'content' => $chunk->content,
                    'metadata' => $chunk->metadata,
                    'document' => [
                        'id' => $chunk->document?->id,
                        'source_system' => $chunk->document?->source_system,
                        'external_id' => $chunk->document?->external_id,
                        'file_name' => $chunk->document?->file_name,
                        'file_type' => $chunk->document?->file_type,
                        'business_context' => $chunk->document?->business_context,
                        'context_key' => $chunk->document?->context_key,
                        'metadata' => $chunk->document?->metadata,
                    ],
                ];
            })
            ->sortByDesc('score')
            ->filter(fn (array $result) => $result['score'] > 0)
            ->take($limit)
            ->values()
            ->all();
    }
}
