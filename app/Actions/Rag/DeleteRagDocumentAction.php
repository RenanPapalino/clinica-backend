<?php

namespace App\Actions\Rag;

use App\Models\RagDocument;
use Illuminate\Support\Facades\DB;

class DeleteRagDocumentAction
{
    public function execute(string $sourceSystem, string $externalId): ?RagDocument
    {
        return DB::transaction(function () use ($sourceSystem, $externalId) {
            $document = RagDocument::where('source_system', $sourceSystem)
                ->where('external_id', $externalId)
                ->first();

            if (!$document) {
                return null;
            }

            $document->chunks()->update(['is_active' => false]);
            $document->status = 'deleted';
            $document->save();
            $document->delete();

            return $document;
        });
    }
}
