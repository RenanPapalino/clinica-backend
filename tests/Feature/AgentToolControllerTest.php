<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Fornecedor;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentToolControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_runtime_retorna_contexto_da_sessao(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'role' => 'admin',
            'ativo' => true,
        ]);

        ChatMessage::create([
            'user_id' => $user->id,
            'session_id' => 'sessao-1',
            'role' => 'user',
            'content' => 'Quais títulos estão vencidos?',
        ]);

        ChatMessage::create([
            'user_id' => $user->id,
            'session_id' => 'sessao-1',
            'role' => 'assistant',
            'content' => 'Encontrei 3 títulos vencidos.',
        ]);

        $this->postJson('/api/internal/agent/session-context', [
            'session_id' => 'sessao-1',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_agent_runtime_busca_conhecimento_por_contexto_de_negocio(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $financeiro = RagDocument::create([
            'source_system' => 'google_drive',
            'external_id' => 'doc-fin',
            'file_name' => 'manual-financeiro.pdf',
            'business_context' => 'financeiro',
            'context_key' => 'geral',
            'status' => 'active',
            'current_version' => 1,
            'chunks_count' => 1,
            'last_indexed_at' => now(),
            'metadata' => ['folder' => 'Financeiro'],
        ]);

        RagChunk::create([
            'rag_document_id' => $financeiro->id,
            'version' => 1,
            'chunk_index' => 0,
            'content' => 'Manual financeiro com regras de boleto em atraso e cobranca.',
            'metadata' => ['page' => 1],
            'is_active' => true,
        ]);

        $produto = RagDocument::create([
            'source_system' => 'google_drive',
            'external_id' => 'doc-prod',
            'file_name' => 'faq-produto.pdf',
            'business_context' => 'produto',
            'context_key' => 'geral',
            'status' => 'active',
            'current_version' => 1,
            'chunks_count' => 1,
            'last_indexed_at' => now(),
            'metadata' => ['folder' => 'Produto'],
        ]);

        RagChunk::create([
            'rag_document_id' => $produto->id,
            'version' => 1,
            'chunk_index' => 0,
            'content' => 'FAQ do sistema com atalhos de navegacao e configuracao.',
            'metadata' => ['page' => 1],
            'is_active' => true,
        ]);

        $this->postJson('/api/internal/agent/knowledge/search', [
            'query' => 'boleto atraso',
            'business_context' => 'financeiro',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document.file_name', 'manual-financeiro.pdf')
            ->assertJsonPath('data.0.document.business_context', 'financeiro');
    }

    public function test_agent_runtime_cria_cliente_conta_receber_e_conta_pagar(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $clienteResponse = $this->postJson('/api/internal/agent/clientes', [
            'cnpj' => '12.345.678/0001-90',
            'razao_social' => 'CLINICA SST TESTE LTDA',
            'nome_fantasia' => 'CLINICA TESTE',
            'email' => 'financeiro@clinica-teste.com',
            'telefone' => '11999990000',
        ], $this->headersFor($user));

        $clienteResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.razao_social', 'CLINICA SST TESTE LTDA');

        $clienteId = $clienteResponse->json('data.id');

        $this->postJson('/api/internal/agent/contas-receber', [
            'cliente_id' => $clienteId,
            'descricao' => 'Mensalidade de abril',
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_original' => 750.50,
        ], $this->headersFor($user))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'receber')
            ->assertJsonPath('data.status', 'aberto');

        $fornecedor = Fornecedor::create([
            'razao_social' => 'LABORATORIO APOIO LTDA',
            'cnpj' => '11222333000144',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/contas-pagar', [
            'descricao' => 'Pagamento laboratorio parceiro',
            'valor' => 320.25,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'fornecedor_id' => $fornecedor->id,
        ], $this->headersFor($user))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.descricao', 'Pagamento laboratorio parceiro')
            ->assertJsonPath('data.status', 'pendente');

        $this->assertDatabaseHas('clientes', [
            'id' => $clienteId,
            'cnpj' => '12345678000190',
        ]);

        $this->assertDatabaseHas('titulos', [
            'cliente_id' => $clienteId,
            'descricao' => 'Mensalidade de abril',
            'tipo' => 'receber',
            'status' => 'aberto',
            'valor_original' => 750.50,
        ]);

        $this->assertDatabaseHas('despesas', [
            'descricao' => 'Pagamento laboratorio parceiro',
            'fornecedor_id' => $fornecedor->id,
            'status' => 'pendente',
            'valor' => 320.25,
        ]);
    }

    public function test_agent_runtime_busca_despesas_existentes(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $fornecedor = Fornecedor::create([
            'razao_social' => 'SERVICOS MEDICOS LTDA',
            'cnpj' => '99888777000166',
            'status' => 'ativo',
        ]);

        Despesa::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Boleto exames complementares',
            'valor' => 150.00,
            'valor_original' => 150.00,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'status' => 'pendente',
        ]);

        $this->postJson('/api/internal/agent/despesas/search', [
            'query' => 'boleto exames',
            'status' => 'pendente',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.descricao', 'Boleto exames complementares');
    }

    public function test_agent_runtime_busca_fornecedores(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        Fornecedor::create([
            'razao_social' => 'LAB APOIO SST LTDA',
            'nome_fantasia' => 'LAB APOIO',
            'cnpj' => '55444333000122',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/fornecedores/search', [
            'query' => 'lab apoio',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.razao_social', 'LAB APOIO SST LTDA');
    }

    public function test_agent_runtime_exige_secret_valido(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $this->postJson('/api/internal/agent/session-context', [
            'session_id' => 'sessao-1',
        ], [
            'X-Agent-Secret' => 'secret-invalido',
            'X-Agent-User-Id' => $user->id,
        ])->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    private function headersFor(User $user): array
    {
        return [
            'X-Agent-Secret' => 'agent-secret',
            'X-Agent-User-Id' => (string) $user->id,
        ];
    }
}
