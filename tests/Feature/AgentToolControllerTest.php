<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Despesa;
use App\Models\Fatura;
use App\Models\Fornecedor;
use App\Models\Nfse;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\Servico;
use App\Models\Titulo;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    public function test_agent_runtime_consulta_cnpj_com_pontuacao_e_retorna_dados_estruturados(): void
    {
        config([
            'chatbot.runtime.secret' => 'agent-secret',
            'services.cnpja.api_key' => 'api-key-teste',
            'services.cnpja.base_url' => 'https://api.cnpja.com',
            'services.cnpja.include_simples' => true,
        ]);

        Http::fake([
            'https://api.cnpja.com/office/04252011000110*' => Http::response([
                'company' => [
                    'name' => 'GOOGLE BRASIL INTERNET LTDA',
                    'alias' => 'GOOGLE BRASIL',
                    'nature' => ['text' => 'Sociedade Empresária Limitada'],
                    'size' => ['text' => 'Demais'],
                    'municipalRegistration' => '123456',
                    'website' => 'https://www.google.com.br',
                ],
                'status' => ['text' => 'Ativa'],
                'address' => [
                    'street' => 'Avenida Brigadeiro Faria Lima',
                    'number' => '3477',
                    'district' => 'Itaim Bibi',
                    'city' => 'São Paulo',
                    'state' => 'SP',
                    'zip' => '04538133',
                ],
                'emails' => [
                    ['address' => 'contato@google.com'],
                ],
                'phones' => [
                    ['area' => '11', 'number' => '23950000'],
                ],
                'registrations' => [
                    ['state' => 'SP', 'number' => '148765432'],
                ],
                'simples' => ['optant' => false],
                'founded' => '2005-09-15',
            ], 200),
        ]);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'cnpj' => '04252011000110',
            'razao_social' => 'GOOGLE BRASIL INTERNET LTDA',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/cnpj/consultar', [
            'cnpj' => '04.252.011/0001-10',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cnpj', '04252011000110')
            ->assertJsonPath('data.cnpj_formatado', '04.252.011/0001-10')
            ->assertJsonPath('data.provider', 'cnpja_commercial')
            ->assertJsonPath('data.empresa.razao_social', 'GOOGLE BRASIL INTERNET LTDA')
            ->assertJsonPath('data.empresa.endereco.cidade', 'São Paulo')
            ->assertJsonPath('data.cliente_existente.id', $cliente->id)
            ->assertJsonPath('data.cliente_existente.cnpj_formatado', '04.252.011/0001-10');
    }

    public function test_agent_runtime_consulta_cnpj_sem_pontuacao(): void
    {
        config([
            'chatbot.runtime.secret' => 'agent-secret',
            'services.cnpja.api_key' => 'api-key-teste',
            'services.cnpja.base_url' => 'https://api.cnpja.com',
            'services.cnpja.include_simples' => true,
        ]);

        Http::fake([
            'https://api.cnpja.com/office/04252011000110*' => Http::response([
                'company' => [
                    'name' => 'GOOGLE BRASIL INTERNET LTDA',
                ],
                'status' => ['text' => 'Ativa'],
            ], 200),
        ]);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $this->postJson('/api/internal/agent/cnpj/consultar', [
            'cnpj' => '04252011000110',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cnpj', '04252011000110')
            ->assertJsonPath('data.empresa.razao_social', 'GOOGLE BRASIL INTERNET LTDA');
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

    public function test_agent_runtime_busca_conhecimento_inclui_uploads_do_usuario_e_exclui_uploads_de_outros(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $global = RagDocument::create([
            'source_system' => 'google_drive',
            'external_id' => 'global-1',
            'file_name' => 'manual-global.txt',
            'business_context' => 'financeiro',
            'context_key' => 'geral',
            'status' => 'active',
            'current_version' => 1,
            'chunks_count' => 1,
            'last_indexed_at' => now(),
            'metadata' => ['source_scope' => 'global'],
        ]);

        RagChunk::create([
            'rag_document_id' => $global->id,
            'version' => 1,
            'chunk_index' => 0,
            'content' => 'Regra global sobre faturamento e cobrança clínica.',
            'metadata' => [],
            'is_active' => true,
        ]);

        $ownedUpload = RagDocument::create([
            'source_system' => 'chatbot_upload',
            'external_id' => 'chat-1',
            'file_name' => 'base-cliente.txt',
            'business_context' => 'chat_upload',
            'context_key' => 'chat_user_' . $user->id,
            'status' => 'active',
            'current_version' => 1,
            'chunks_count' => 1,
            'last_indexed_at' => now(),
            'metadata' => ['source_scope' => 'chat_upload'],
        ]);

        RagChunk::create([
            'rag_document_id' => $ownedUpload->id,
            'version' => 1,
            'chunk_index' => 0,
            'content' => 'Alpha Sistemas Ltda contratou PCMSO e exames admissionais.',
            'metadata' => [],
            'is_active' => true,
        ]);

        $foreignUpload = RagDocument::create([
            'source_system' => 'chatbot_upload',
            'external_id' => 'chat-2',
            'file_name' => 'base-outro-usuario.txt',
            'business_context' => 'chat_upload',
            'context_key' => 'chat_user_999',
            'status' => 'active',
            'current_version' => 1,
            'chunks_count' => 1,
            'last_indexed_at' => now(),
            'metadata' => ['source_scope' => 'chat_upload'],
        ]);

        RagChunk::create([
            'rag_document_id' => $foreignUpload->id,
            'version' => 1,
            'chunk_index' => 0,
            'content' => 'Documento privado de outro usuário sobre cobrança confidencial.',
            'metadata' => [],
            'is_active' => true,
        ]);

        $this->postJson('/api/internal/agent/knowledge/search', [
            'query' => 'Alpha Sistemas',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document.file_name', 'base-cliente.txt');
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

    public function test_agent_runtime_busca_faturas_por_status_e_nfse(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '12345678000190',
            'razao_social' => 'CLINICA FATURAMENTO LTDA',
        ]);

        Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-001',
            'data_emissao' => now()->subDays(2)->toDateString(),
            'data_vencimento' => now()->addDays(8)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 1200.00,
            'valor_total' => 1200.00,
            'status' => 'pendente',
            'nfse_emitida' => false,
            'observacoes' => 'Detalhes analíticos do anexo: Funcionários vinculados (2): João e Maria. Exames identificados: Audiometria e Glicose.',
            'metadata' => [
                'unidade' => 'ALPHATECH',
                'numero_funcionarios' => 2,
                'funcionarios' => [
                    ['nome' => 'João', 'setor' => 'Comercial', 'matricula' => 1],
                    ['nome' => 'Maria', 'setor' => 'Copa', 'matricula' => 3],
                ],
                'exames' => [
                    ['nome' => 'Audiometria', 'quantidade' => 1, 'valor_cobrar' => 40],
                    ['nome' => 'Glicose', 'quantidade' => 1, 'valor_cobrar' => 10],
                ],
                'quantidade_exames_registrados' => 2,
            ],
        ]);

        Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-002',
            'data_emissao' => now()->subDays(1)->toDateString(),
            'data_vencimento' => now()->addDays(12)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 900.00,
            'valor_total' => 900.00,
            'status' => 'emitida',
            'nfse_emitida' => true,
        ]);

        $this->postJson('/api/internal/agent/faturas/search', [
            'cliente_id' => $cliente->id,
            'status' => 'pendente',
            'nfse_emitida' => false,
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_fatura', 'FAT-001')
            ->assertJsonPath('data.0.nfse_emitida', false)
            ->assertJsonPath('data.0.unidade_anexo', 'ALPHATECH')
            ->assertJsonPath('data.0.funcionarios_total', 2)
            ->assertJsonPath('data.0.funcionarios_resumo.0', 'João (Comercial, matrícula 1)')
            ->assertJsonPath('data.0.exames_resumo.0', 'Audiometria (1x, R$ 40,00)');
    }

    public function test_agent_runtime_busca_nfse_por_status_ou_protocolo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '88776655000199',
            'razao_social' => 'CLINICA NFSE LTDA',
        ]);

        $fatura = Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-NFSE-001',
            'data_emissao' => now()->subDays(2)->toDateString(),
            'data_vencimento' => now()->addDays(8)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 700.00,
            'valor_total' => 700.00,
            'status' => 'emitida',
            'nfse_emitida' => true,
            'nfse_numero' => 'NFSE-001',
        ]);

        Nfse::create([
            'fatura_id' => $fatura->id,
            'cliente_id' => $cliente->id,
            'numero_nfse' => 'NFSE-001',
            'codigo_verificacao' => 'ABCD1234',
            'protocolo' => 'PROTO-XYZ',
            'data_envio' => now()->subDays(2),
            'data_emissao' => now()->subDays(2),
            'data_autorizacao' => now()->subDays(2),
            'valor_servicos' => 700.00,
            'valor_deducoes' => 0,
            'valor_iss' => 35.00,
            'aliquota_iss' => 5.00,
            'valor_liquido' => 665.00,
            'status' => 'emitida',
            'codigo_servico' => '17.01',
            'discriminacao' => 'Mensalidade clinica.',
        ]);

        $this->postJson('/api/internal/agent/nfse/search', [
            'query' => 'PROTO-XYZ',
            'status' => 'emitida',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_nfse', 'NFSE-001')
            ->assertJsonPath('data.0.protocolo', 'PROTO-XYZ')
            ->assertJsonPath('data.0.status', 'emitida');
    }

    public function test_agent_runtime_retorna_resumo_de_faturamento_por_periodo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '22345678000190',
            'razao_social' => 'CLINICA ANALITICA LTDA',
        ]);

        Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-100',
            'data_emissao' => now()->subDays(10)->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 1000.00,
            'valor_total' => 1000.00,
            'status' => 'pendente',
            'nfse_emitida' => false,
        ]);

        Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-101',
            'data_emissao' => now()->subDays(3)->toDateString(),
            'data_vencimento' => now()->addDays(12)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 500.00,
            'valor_total' => 500.00,
            'status' => 'emitida',
            'nfse_emitida' => true,
        ]);

        $this->postJson('/api/internal/agent/faturamento/summary', [
            'periodo_inicio' => now()->subDays(30)->toDateString(),
            'periodo_fim' => now()->toDateString(),
            'cliente_id' => $cliente->id,
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_faturado', 1500)
            ->assertJsonPath('data.quantidade_faturas', 2)
            ->assertJsonPath('data.ticket_medio', 750);
    }

    public function test_agent_runtime_retorna_previsao_de_caixa_por_periodo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '11995544000188',
            'razao_social' => 'CLINICA CAIXA LTDA',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Recebimento previsto',
            'tipo' => 'receber',
            'numero_titulo' => 'CX-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_original' => 2000.00,
            'valor_pago' => 0,
            'valor_saldo' => 2000.00,
            'status' => 'aberto',
        ]);

        Despesa::create([
            'descricao' => 'Pagamento previsto',
            'valor' => 650.00,
            'valor_original' => 650.00,
            'valor_pago' => 0,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'status' => 'pendente',
        ]);

        $this->postJson('/api/internal/agent/caixa/previsao', [
            'periodo_inicio' => now()->toDateString(),
            'periodo_fim' => now()->addDays(15)->toDateString(),
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entradas_previstas', 2000)
            ->assertJsonPath('data.saidas_previstas', 650)
            ->assertJsonPath('data.saldo_previsto', 1350);
    }

    public function test_agent_runtime_retorna_resumo_de_fechamento_diario(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '66442211000155',
            'razao_social' => 'CLINICA FECHAMENTO LTDA',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Recebimento previsto do dia',
            'tipo' => 'receber',
            'numero_titulo' => 'FCH-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_original' => 100.00,
            'valor_pago' => 0,
            'valor_saldo' => 100.00,
            'status' => 'aberto',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Recebimento realizado no dia',
            'tipo' => 'receber',
            'numero_titulo' => 'FCH-002',
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'data_pagamento' => now()->toDateString(),
            'valor_original' => 80.00,
            'valor_pago' => 80.00,
            'valor_saldo' => 0.00,
            'status' => 'pago',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Titulo vencido',
            'tipo' => 'receber',
            'numero_titulo' => 'FCH-003',
            'data_emissao' => now()->subDays(10)->toDateString(),
            'data_vencimento' => now()->subDays(2)->toDateString(),
            'valor_original' => 60.00,
            'valor_pago' => 0,
            'valor_saldo' => 60.00,
            'status' => 'aberto',
        ]);

        Despesa::create([
            'descricao' => 'Pagamento previsto do dia',
            'valor' => 40.00,
            'valor_original' => 40.00,
            'valor_pago' => 0,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'status' => 'pendente',
        ]);

        Despesa::create([
            'descricao' => 'Pagamento realizado no dia',
            'valor' => 30.00,
            'valor_original' => 30.00,
            'valor_pago' => 30.00,
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'data_pagamento' => now()->toDateString(),
            'status' => 'pago',
        ]);

        Despesa::create([
            'descricao' => 'Despesa vencida',
            'valor' => 20.00,
            'valor_original' => 20.00,
            'valor_pago' => 0,
            'data_emissao' => now()->subDays(10)->toDateString(),
            'data_vencimento' => now()->subDays(3)->toDateString(),
            'status' => 'pendente',
        ]);

        Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-FECH-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 500.00,
            'valor_total' => 500.00,
            'status' => 'pendente',
            'nfse_emitida' => false,
        ]);

        $faturaNfsePendente = Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-FECH-002',
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 350.00,
            'valor_total' => 350.00,
            'status' => 'emitida',
            'nfse_emitida' => false,
        ]);

        $faturaNfseErro = Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-FECH-003',
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 200.00,
            'valor_total' => 200.00,
            'status' => 'emitida',
            'nfse_emitida' => false,
        ]);

        Nfse::create([
            'fatura_id' => $faturaNfsePendente->id,
            'cliente_id' => $cliente->id,
            'protocolo' => 'PEND-001',
            'data_envio' => now(),
            'valor_servicos' => 350.00,
            'valor_deducoes' => 0,
            'valor_iss' => 17.50,
            'aliquota_iss' => 5.00,
            'valor_liquido' => 332.50,
            'status' => 'pendente',
        ]);

        Nfse::create([
            'fatura_id' => $faturaNfseErro->id,
            'cliente_id' => $cliente->id,
            'protocolo' => 'ERRO-001',
            'data_envio' => now(),
            'valor_servicos' => 200.00,
            'valor_deducoes' => 0,
            'valor_iss' => 10.00,
            'aliquota_iss' => 5.00,
            'valor_liquido' => 190.00,
            'status' => 'erro',
            'mensagem_erro' => 'Falha na prefeitura',
        ]);

        $this->postJson('/api/internal/agent/fechamento/diario', [
            'data' => now()->toDateString(),
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recebimentos_previstos_hoje.valor_total', 100)
            ->assertJsonPath('data.pagamentos_previstos_hoje.valor_total', 40)
            ->assertJsonPath('data.recebimentos_realizados_hoje.valor_total', 80)
            ->assertJsonPath('data.pagamentos_realizados_hoje.valor_total', 30)
            ->assertJsonPath('data.saldo_realizado_hoje', 50)
            ->assertJsonPath('data.titulos_vencidos_abertos.quantidade', 1)
            ->assertJsonPath('data.despesas_vencidas_abertas.quantidade', 1)
            ->assertJsonPath('data.faturas_pendentes.quantidade', 1)
            ->assertJsonPath('data.nfse_pendentes.quantidade', 1)
            ->assertJsonPath('data.nfse_com_erro.quantidade', 1);
    }

    public function test_agent_runtime_retorna_inadimplentes_para_automacao_de_cobranca(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '55443322000111',
            'razao_social' => 'CLINICA COBRANCA LTDA',
            'email' => 'financeiro@clinica-cobranca.com',
            'telefone' => '11999990000',
            'celular' => '11999990000',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade vencida 1',
            'tipo' => 'receber',
            'numero_titulo' => 'COB-001',
            'data_emissao' => now()->subDays(40)->toDateString(),
            'data_vencimento' => now()->subDays(15)->toDateString(),
            'valor_original' => 900.00,
            'valor_pago' => 0,
            'valor_saldo' => 900.00,
            'status' => 'aberto',
        ]);

        Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade vencida 2',
            'tipo' => 'receber',
            'numero_titulo' => 'COB-002',
            'data_emissao' => now()->subDays(30)->toDateString(),
            'data_vencimento' => now()->subDays(8)->toDateString(),
            'valor_original' => 700.00,
            'valor_pago' => 0,
            'valor_saldo' => 700.00,
            'status' => 'aberto',
        ]);

        $this->postJson('/api/internal/agent/cobrancas/inadimplentes', [
            'min_dias_atraso' => 5,
            'valor_minimo' => 100,
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.total_clientes', 1)
            ->assertJsonPath('data.stats.titulos_vencidos', 2)
            ->assertJsonPath('data.clientes.0.cliente_id', $cliente->id)
            ->assertJsonPath('data.clientes.0.canal_sugerido', 'whatsapp')
            ->assertJsonPath('data.clientes.0.total_em_aberto', 1600)
            ->assertJsonPath('data.clientes.0.prioridade', 'media')
            ->assertJsonCount(2, 'data.clientes.0.titulos');
    }

    public function test_agent_runtime_registra_evento_de_cobranca_de_automacao(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '44553322000199',
            'razao_social' => 'CLINICA LOG COBRANCA LTDA',
        ]);

        $titulo = Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade vencida para log',
            'tipo' => 'receber',
            'numero_titulo' => 'LOG-001',
            'data_emissao' => now()->subDays(20)->toDateString(),
            'data_vencimento' => now()->subDays(12)->toDateString(),
            'valor_original' => 450.00,
            'valor_pago' => 0,
            'valor_saldo' => 450.00,
            'status' => 'aberto',
        ]);

        $this->postJson('/api/internal/agent/cobrancas/registrar', [
            'cliente_id' => $cliente->id,
            'titulo_id' => $titulo->id,
            'meio' => 'whatsapp',
            'status' => 'enviada',
            'canal' => 'n8n',
            'descricao' => 'Mensagem de cobranca enviada automaticamente.',
            'valor_cobrado' => 450.00,
        ], $this->headersFor($user))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cliente_id', $cliente->id)
            ->assertJsonPath('data.titulo_id', $titulo->id)
            ->assertJsonPath('data.meio', 'whatsapp')
            ->assertJsonPath('data.status', 'enviada');

        $this->assertDatabaseHas('cobrancas', [
            'cliente_id' => $cliente->id,
            'titulo_id' => $titulo->id,
            'meio' => 'whatsapp',
            'canal' => 'n8n',
            'status' => 'enviada',
            'valor_cobrado' => 450.00,
        ]);
    }

    public function test_agent_runtime_atualiza_status_do_cliente(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '99887766000155',
            'razao_social' => 'CLINICA STATUS LTDA',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/clientes/status', [
            'cliente_id' => $cliente->id,
            'status' => 'inativo',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $cliente->id)
            ->assertJsonPath('data.status', 'inativo');

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'status' => 'inativo',
        ]);
    }

    public function test_agent_runtime_baixa_titulo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '44556677000188',
            'razao_social' => 'CLINICA TITULO LTDA',
        ]);

        $titulo = Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade abril',
            'tipo' => 'receber',
            'numero_titulo' => 'TIT-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'valor_original' => 800.00,
            'valor_pago' => 0,
            'valor_saldo' => 800.00,
            'status' => 'aberto',
        ]);

        $this->postJson('/api/internal/agent/titulos/baixar', [
            'titulo_id' => $titulo->id,
            'valor' => 800.00,
            'forma_pagamento' => 'pix',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $titulo->id)
            ->assertJsonPath('data.status', 'pago')
            ->assertJsonPath('data.forma_pagamento', 'pix');

        $this->assertDatabaseHas('titulos', [
            'id' => $titulo->id,
            'status' => 'pago',
            'valor_pago' => 800.00,
            'valor_saldo' => 0.00,
        ]);
    }

    public function test_agent_runtime_renegocia_titulo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '55667788000122',
            'razao_social' => 'CLINICA RENEGOCIACAO LTDA',
        ]);

        $titulo = Titulo::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade maio',
            'tipo' => 'receber',
            'numero_titulo' => 'REN-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(4)->toDateString(),
            'valor_original' => 900.00,
            'valor_pago' => 0,
            'valor_saldo' => 900.00,
            'status' => 'aberto',
        ]);

        $novoVencimento = now()->addDays(20)->toDateString();

        $this->postJson('/api/internal/agent/titulos/renegociar', [
            'titulo_id' => $titulo->id,
            'nova_data_vencimento' => $novoVencimento,
            'observacoes' => 'Renegociado via chatbot.',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $titulo->id)
            ->assertJsonPath('data.status', 'aberto');

        $this->assertDatabaseHas('titulos', [
            'id' => $titulo->id,
            'status' => 'aberto',
            'data_vencimento' => $novoVencimento . ' 00:00:00',
        ]);
    }

    public function test_agent_runtime_baixa_despesa(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $fornecedor = Fornecedor::create([
            'razao_social' => 'FORNECEDOR BAIXA LTDA',
            'cnpj' => '88776655000144',
            'status' => 'ativo',
        ]);

        $despesa = Despesa::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Boleto laboratorio',
            'valor' => 450.00,
            'valor_original' => 450.00,
            'valor_pago' => 0,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(2)->toDateString(),
            'status' => 'pendente',
        ]);

        $this->postJson('/api/internal/agent/despesas/baixar', [
            'despesa_id' => $despesa->id,
            'valor' => 450.00,
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $despesa->id)
            ->assertJsonPath('data.status', 'pago')
            ->assertJsonPath('data.valor_pago', '450.00');

        $this->assertDatabaseHas('despesas', [
            'id' => $despesa->id,
            'status' => 'pago',
            'valor_pago' => 450.00,
        ]);
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

    public function test_agent_runtime_cria_fatura_manual_com_titulo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '12345678000190',
            'razao_social' => 'CLINICA FATURA TESTE LTDA',
        ]);

        $servico = Servico::create([
            'codigo' => 'EXAME-PERIODICO',
            'descricao' => 'Exame periodico',
            'valor_unitario' => 120.00,
            'tipo_servico' => 'exame',
            'ativo' => true,
        ]);

        $response = $this->postJson('/api/internal/agent/faturas', [
            'cliente_id' => $cliente->id,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(15)->toDateString(),
            'periodo_referencia' => '2026-03',
            'observacoes' => 'Detalhes analíticos do anexo: Funcionários vinculados (2): Joao e Maria. Exames identificados: Audiometria e Glicose.',
            'metadata' => [
                'origem_importacao' => 'planilha_fatura',
                'unidade' => 'ALPHATECH',
                'numero_funcionarios' => 2,
                'funcionarios' => [
                    ['nome' => 'Joao', 'setor' => 'Comercial'],
                    ['nome' => 'Maria', 'setor' => 'Copa'],
                ],
                'exames' => [
                    ['nome' => 'Audiometria', 'quantidade' => 1, 'valor_cobrar' => 40],
                    ['nome' => 'Glicose', 'quantidade' => 1, 'valor_cobrar' => 10],
                ],
            ],
            'itens' => [
                [
                    'servico_id' => $servico->id,
                    'descricao' => 'Exame periodico',
                    'quantidade' => 3,
                    'valor_unitario' => 120.00,
                ],
            ],
        ], $this->headersFor($user));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cliente_id', $cliente->id)
            ->assertJsonPath('data.valor_servicos', 360)
            ->assertJsonPath('data.itens.0.descricao', 'Exame periodico')
            ->assertJsonPath('data.metadata.unidade', 'ALPHATECH')
            ->assertJsonPath('data.metadata.funcionarios.0.nome', 'Joao');

        $faturaId = $response->json('data.id');

        $this->assertDatabaseHas('faturas', [
            'id' => $faturaId,
            'cliente_id' => $cliente->id,
            'periodo_referencia' => '2026-03',
        ]);

        $this->assertSame('ALPHATECH', Fatura::findOrFail($faturaId)->metadata['unidade'] ?? null);

        $this->assertDatabaseHas('titulos', [
            'fatura_id' => $faturaId,
            'tipo' => 'receber',
            'valor_original' => 360.00,
        ]);

        $this->assertSame(1, Fatura::with('itens')->findOrFail($faturaId)->itens->count());
    }

    public function test_agent_runtime_emite_nfse_para_fatura(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '44556677000133',
            'razao_social' => 'CLINICA EMISSAO LTDA',
            'aliquota_iss' => 5,
        ]);

        $fatura = Fatura::create([
            'cliente_id' => $cliente->id,
            'numero_fatura' => 'FAT-NOVA-001',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'periodo_referencia' => '2026-03',
            'valor_servicos' => 1000.00,
            'valor_total' => 1000.00,
            'status' => 'pendente',
            'nfse_emitida' => false,
            'observacoes' => 'Mensalidade ocupacional.',
        ]);

        $this->postJson('/api/internal/agent/nfse/emitir', [
            'fatura_id' => $fatura->id,
            'codigo_servico' => '17.01',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nfse.fatura_id', $fatura->id)
            ->assertJsonPath('data.nfse.status', 'emitida')
            ->assertJsonPath('data.nfse.codigo_servico', '17.01')
            ->assertJsonPath('data.fatura.nfse_emitida', true)
            ->assertJsonPath('data.fatura.status', 'emitida');

        $this->assertDatabaseHas('nfse', [
            'fatura_id' => $fatura->id,
            'status' => 'emitida',
            'codigo_servico' => '17.01',
        ]);

        $this->assertDatabaseHas('faturas', [
            'id' => $fatura->id,
            'nfse_emitida' => true,
            'status' => 'emitida',
        ]);
    }

    public function test_agent_runtime_prepara_preview_de_sincronizacao_de_clientes(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '12345678000190',
            'razao_social' => 'CLINICA ALFA SST LTDA',
            'nome_fantasia' => 'ALFA SST',
            'email' => 'financeiro@alfa.com.br',
            'telefone' => '1130303030',
            'cidade' => 'São Paulo',
            'uf' => 'SP',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/clientes/upsert', [
            'dry_run' => true,
            'cnpj' => '12.345.678/0001-90',
            'razao_social' => 'CLINICA ALFA SST LTDA',
            'nome_fantasia' => 'ALFA SST',
            'email' => 'novo-financeiro@alfa.com.br',
            'telefone' => '1140404040',
            'cidade' => 'São Paulo',
            'uf' => 'SP',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sync_operation', 'atualizar')
            ->assertJsonPath('data.cliente_id', $cliente->id)
            ->assertJsonPath('data.matched_by', 'cnpj')
            ->assertJsonPath('data.changed_fields.0', 'email');
    }

    public function test_agent_runtime_sincroniza_cliente_existente_ou_cria_novo(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        $cliente = Cliente::factory()->create([
            'cnpj' => '99887766000155',
            'razao_social' => 'CLINICA BETA SST LTDA',
            'email' => 'contato@beta.com.br',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/clientes/upsert', [
            'cnpj' => '99.887.766/0001-55',
            'razao_social' => 'CLINICA BETA SST LTDA',
            'email' => 'financeiro@beta.com.br',
            'status' => 'ativo',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sync_operation', 'atualizar')
            ->assertJsonPath('data.cliente.id', $cliente->id);

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'email' => 'financeiro@beta.com.br',
        ]);

        $this->postJson('/api/internal/agent/clientes/upsert', [
            'cnpj' => '55.444.333/0001-22',
            'razao_social' => 'CLINICA NOVA CADASTRADA LTDA',
            'nome_fantasia' => 'NOVA CADASTRADA',
            'status' => 'ativo',
        ], $this->headersFor($user))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sync_operation', 'criar')
            ->assertJsonPath('data.cliente.razao_social', 'CLINICA NOVA CADASTRADA LTDA');

        $this->assertDatabaseHas('clientes', [
            'cnpj' => '55444333000122',
            'razao_social' => 'CLINICA NOVA CADASTRADA LTDA',
        ]);
    }

    public function test_agent_runtime_sincroniza_cliente_em_schema_legado_com_endereco(): void
    {
        config(['chatbot.runtime.secret' => 'agent-secret']);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        if (Schema::hasColumn('clientes', 'logradouro')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->dropColumn('logradouro');
            });
        }

        if (!Schema::hasColumn('clientes', 'endereco')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->string('endereco')->nullable();
            });
        }

        $cliente = Cliente::create([
            'cnpj' => '11222333000199',
            'razao_social' => 'CLINICA LEGADO LTDA',
            'email' => 'contato@legado.com.br',
            'endereco' => 'Rua Antiga',
            'status' => 'ativo',
        ]);

        $this->postJson('/api/internal/agent/clientes/upsert', [
            'cnpj' => '11.222.333/0001-99',
            'razao_social' => 'CLINICA LEGADO LTDA',
            'email' => 'financeiro@legado.com.br',
            'logradouro' => 'Rua Nova',
            'cep' => '01010-000',
            'status' => 'ativo',
        ], $this->headersFor($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sync_operation', 'atualizar')
            ->assertJsonPath('data.cliente.id', $cliente->id);

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'email' => 'financeiro@legado.com.br',
            'endereco' => 'Rua Nova',
            'cep' => '01010-000',
        ]);
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
