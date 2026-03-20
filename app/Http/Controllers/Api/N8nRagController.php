<?php

namespace App\Http\Controllers\Api;

use App\Actions\Rag\DeleteRagDocumentAction;
use App\Actions\Rag\UpsertRagDocumentAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class N8nRagController extends Controller
{
    public function upsert(Request $request, UpsertRagDocumentAction $upsertRagDocumentAction)
    {
        $maxChunkLength = (int) config('chatbot.rag.max_chunk_length', 8000);

        $data = $request->validate([
            'source_system' => 'required|string|max:50',
            'external_id' => 'required|string|max:191',
            'file_name' => 'required|string|max:255',
            'file_type' => 'nullable|string|max:120',
            'business_context' => 'nullable|string|max:120',
            'context_key' => 'nullable|string|max:120',
            'checksum' => 'nullable|string|max:120',
            'external_updated_at' => 'nullable|date',
            'metadata' => 'nullable|array',
            'chunks' => 'required|array|min:1',
            'chunks.*.chunk_index' => 'nullable|integer|min:0',
            'chunks.*.content' => 'required|string|max:' . $maxChunkLength,
            'chunks.*.metadata' => 'nullable|array',
        ]);

        $result = $upsertRagDocumentAction->execute($data);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'status' => $result['status'],
                'document_id' => $result['document']->id,
                'external_id' => $result['document']->external_id,
                'current_version' => $result['document']->current_version,
                'chunks_count' => $result['document']->chunks_count,
                'business_context' => $result['document']->business_context,
            ],
        ]);
    }

    public function delete(Request $request, DeleteRagDocumentAction $deleteRagDocumentAction)
    {
        $data = $request->validate([
            'source_system' => 'required|string|max:50',
            'external_id' => 'required|string|max:191',
        ]);

        $document = $deleteRagDocumentAction->execute($data['source_system'], $data['external_id']);

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Documento RAG não encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documento removido do índice lógico com sucesso.',
            'data' => [
                'document_id' => $document->id,
                'external_id' => $document->external_id,
                'status' => 'deleted',
            ],
        ]);
    }
}
