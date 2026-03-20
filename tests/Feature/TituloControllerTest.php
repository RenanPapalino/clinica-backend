<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Titulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TituloControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_um_titulo_com_descricao_e_tipo(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cliente = Cliente::factory()->create();

        $response = $this->postJson('/api/titulos', [
            'cliente_id' => $cliente->id,
            'descricao' => 'Mensalidade SST',
            'numero_titulo' => 'TIT-000123',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_original' => 450.00,
            'status' => 'aberto',
            'tipo' => 'receber',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.descricao', 'Mensalidade SST')
            ->assertJsonPath('data.tipo', 'receber');

        $this->assertDatabaseHas('titulos', [
            'numero_titulo' => 'TIT-000123',
            'descricao' => 'Mensalidade SST',
            'tipo' => 'receber',
            'valor_original' => 450.00,
        ]);
    }

    public function test_baixa_um_titulo_e_marca_como_pago_quando_saldo_zerado(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cliente = Cliente::factory()->create();

        $titulo = Titulo::factory()->create([
            'cliente_id' => $cliente->id,
            'valor_original' => 300.00,
            'valor_saldo' => 300.00,
            'valor_pago' => 0,
            'status' => 'aberto',
        ]);

        $this->postJson("/api/contas-receber/titulos/{$titulo->id}/baixar", [
            'valor' => 300.00,
            'forma_pagamento' => 'pix',
        ])->assertStatus(200);

        $titulo->refresh();

        $this->assertSame('pago', $titulo->status);
        $this->assertSame(0.0, (float) $titulo->valor_saldo);
    }
}
