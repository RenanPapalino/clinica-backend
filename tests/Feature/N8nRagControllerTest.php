<?php

namespace Tests\Feature;

use App\Models\RagChunk;
use App\Models\RagDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class N8nRagControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_n8n_ingest_cria_documento_e_chunks_no_mysql(): void
    {
        config(['chatbot.n8n.ingest_secret' => 'n8n-secret']);

        $response = $this->postJson('/api/internal/n8n/rag/upsert', [
            'source_system' => 'google_drive',
            'external_id' => 'file-001',
            'file_name' => 'manual-financeiro.pdf',
            'file_type' => 'application/pdf',
            'business_context' => 'financeiro',
            'context_key' => 'cliente:1',
            'checksum' => 'checksum-v1',
            'metadata' => [
                'folder_id' => 'folder-123',
                'folder_name' => 'Financeiro',
                'mime_type' => 'application/pdf',
            ],
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'content' => 'Manual financeiro com instrucoes sobre boleto e inadimplencia.',
                    'metadata' => ['page' => 1],
                ],
                [
                    'chunk_index' => 1,
                    'content' => 'Procedimento para contas a receber e conciliacao bancaria.',
                    'metadata' => ['page' => 2],
                ],
            ],
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonPath('data.chunks_count', 2)
            ->assertJsonPath('data.business_context', 'financeiro');

        $this->assertDatabaseHas('rag_documents', [
            'source_system' => 'google_drive',
            'external_id' => 'file-001',
            'file_name' => 'manual-financeiro.pdf',
            'business_context' => 'financeiro',
            'context_key' => 'cliente:1',
            'current_version' => 1,
            'chunks_count' => 2,
            'status' => 'active',
        ]);

        $document = RagDocument::firstOrFail();

        $this->assertSame(2, RagChunk::where('rag_document_id', $document->id)->count());
        $this->assertSame(2, RagChunk::where('rag_document_id', $document->id)->where('is_active', true)->count());
    }

    public function test_n8n_ingest_atualiza_sem_perder_versao_ativa_ate_validar_nova_indexacao(): void
    {
        config(['chatbot.n8n.ingest_secret' => 'n8n-secret']);

        $this->postJson('/api/internal/n8n/rag/upsert', [
            'source_system' => 'google_drive',
            'external_id' => 'file-002',
            'file_name' => 'politica-cobranca.pdf',
            'checksum' => 'checksum-v1',
            'business_context' => 'financeiro',
            'chunks' => [
                ['content' => 'Versao antiga sobre cobranca e atraso.'],
            ],
        ], $this->headers())->assertOk();

        $this->postJson('/api/internal/n8n/rag/upsert', [
            'source_system' => 'google_drive',
            'external_id' => 'file-002',
            'file_name' => 'politica-cobranca.pdf',
            'checksum' => 'checksum-v2',
            'business_context' => 'financeiro',
            'chunks' => [
                ['content' => 'Versao nova sobre cobranca, boleto e juros.'],
                ['content' => 'Fluxo atualizado para negativacao e follow-up.'],
            ],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'updated')
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.chunks_count', 2);

        $document = RagDocument::where('external_id', 'file-002')->firstOrFail();

        $this->assertSame(2, $document->current_version);
        $this->assertSame(2, $document->chunks_count);

        $this->assertDatabaseHas('rag_chunks', [
            'rag_document_id' => $document->id,
            'version' => 1,
            'chunk_index' => 0,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('rag_chunks', [
            'rag_document_id' => $document->id,
            'version' => 2,
            'chunk_index' => 0,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('rag_chunks', [
            'rag_document_id' => $document->id,
            'version' => 2,
            'chunk_index' => 1,
            'is_active' => true,
        ]);
    }

    public function test_n8n_ingest_remove_documento_e_inativa_chunks(): void
    {
        config(['chatbot.n8n.ingest_secret' => 'n8n-secret']);

        $this->postJson('/api/internal/n8n/rag/upsert', [
            'source_system' => 'google_drive',
            'external_id' => 'file-003',
            'file_name' => 'faq-sistema.md',
            'checksum' => 'checksum-v1',
            'business_context' => 'produto',
            'chunks' => [
                ['content' => 'FAQ do sistema sobre cadastro de clientes.'],
            ],
        ], $this->headers())->assertOk();

        $this->postJson('/api/internal/n8n/rag/delete', [
            'source_system' => 'google_drive',
            'external_id' => 'file-003',
        ], $this->headers())->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'deleted');

        $document = RagDocument::withTrashed()->where('external_id', 'file-003')->firstOrFail();

        $this->assertSoftDeleted('rag_documents', [
            'id' => $document->id,
            'source_system' => 'google_drive',
            'external_id' => 'file-003',
        ]);

        $this->assertSame(0, RagChunk::where('rag_document_id', $document->id)->where('is_active', true)->count());
    }

    private function headers(): array
    {
        return [
            'X-N8N-Secret' => 'n8n-secret',
        ];
    }
}
