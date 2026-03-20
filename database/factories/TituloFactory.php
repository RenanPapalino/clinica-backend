<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Titulo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Titulo>
 */
class TituloFactory extends Factory
{
    protected $model = Titulo::class;

    public function definition(): array
    {
        return [
            'tipo' => 'receber',
            'cliente_id' => Cliente::factory(),
            'fatura_id' => null,
            'descricao' => fake()->sentence(3),
            'numero_titulo' => 'TIT-' . fake()->unique()->numerify('######'),
            'nosso_numero' => null,
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'data_pagamento' => null,
            'valor_original' => 100.00,
            'valor_juros' => 0,
            'valor_multa' => 0,
            'valor_desconto' => 0,
            'valor_pago' => 0,
            'valor_saldo' => 100.00,
            'status' => 'aberto',
            'forma_pagamento' => null,
            'codigo_barras' => null,
            'linha_digitavel' => null,
            'url_boleto' => null,
            'observacoes' => null,
            'fornecedor_id' => null,
            'plano_conta_id' => null,
            'centro_custo_id' => null,
            'competencia' => now()->toDateString(),
        ];
    }
}
