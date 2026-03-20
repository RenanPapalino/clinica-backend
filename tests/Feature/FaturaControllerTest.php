<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Fatura;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaturaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_uma_fatura_com_itens(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cliente = Cliente::factory()->create();
        $servico = Servico::create([
            'codigo' => 'EXAM-TESTE',
            'descricao' => 'Exame admissional',
            'valor_unitario' => 100.00,
            'tipo_servico' => 'exame',
            'ativo' => true,
        ]);

        $payload = [
            'cliente_id' => $cliente->id,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(15)->toDateString(),
            'periodo_referencia' => '2025-11',
            'itens' => [
                [
                    'servico_id' => $servico->id,
                    'descricao' => 'Exame admissional',
                    'quantidade' => 10,
                    'valor_unitario' => 100.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/faturamento/faturas', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valor_servicos', 1000);

        $this->assertDatabaseHas('titulos', [
            'fatura_id' => $response->json('data.id'),
            'descricao' => 'Fatura #' . $response->json('data.numero_fatura'),
            'tipo' => 'receber',
            'valor_original' => 1000.00,
        ]);
    }

    public function test_gera_titulo_quando_fatura_muda_para_emitida(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cliente = Cliente::factory()->create();

        $fatura = Fatura::factory()->create([
            'cliente_id' => $cliente->id,
            'valor_total' => 500.00,
            'status' => 'aberta',
        ]);

        $this->putJson("/api/faturamento/faturas/{$fatura->id}", [
            'status' => 'emitida',
        ])->assertStatus(200);

        $this->assertDatabaseHas('titulos', [
            'fatura_id' => $fatura->id,
            'valor_original' => 500.00,
        ]);
    }
}
