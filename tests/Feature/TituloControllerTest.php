<?php

use App\Models\Cliente;
use App\Models\Titulo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('baixa um titulo e marca como pago quando saldo zerado', function () {
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

    expect($titulo->status)->toBe('pago')
        ->and((float) $titulo->valor_saldo)->toBe(0.0);
});
