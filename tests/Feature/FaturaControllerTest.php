<?php

use App\Models\Cliente;
use App\Models\Fatura;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cria uma fatura com itens', function () {
    $cliente = Cliente::factory()->create();

    $payload = [
        'cliente_id' => $cliente->id,
        'data_emissao' => now()->toDateString(),
        'data_vencimento' => now()->addDays(15)->toDateString(),
        'periodo_referencia' => '2025-11',
        'itens' => [
            [
                'descricao' => 'Exame admissional',
                'quantidade' => 10,
                'valor_unitario' => 100.00,
            ],
        ],
    ];

    $response = $this->postJson('/api/faturamento/faturas', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.valor_servicos', 1000.0);
});

it('gera titulo quando fatura muda para emitida', function () {
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
});
