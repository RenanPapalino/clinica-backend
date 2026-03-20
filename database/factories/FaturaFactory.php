<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Fatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fatura>
 */
class FaturaFactory extends Factory
{
    protected $model = Fatura::class;

    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'numero_fatura' => 'FAT-' . now()->format('Ym') . '-' . fake()->unique()->numerify('######'),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(15)->toDateString(),
            'periodo_referencia' => now()->format('Y-m'),
            'valor_servicos' => 100.00,
            'valor_descontos' => 0,
            'valor_acrescimos' => 0,
            'valor_iss' => 0,
            'valor_total' => 100.00,
            'status' => 'aberta',
            'nfse_emitida' => false,
            'observacoes' => null,
            'metadata' => null,
        ];
    }
}
