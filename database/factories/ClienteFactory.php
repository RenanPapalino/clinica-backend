<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'cnpj' => fake()->unique()->numerify('##############'),
            'razao_social' => strtoupper(fake()->company()),
            'nome_fantasia' => strtoupper(fake()->companySuffix()),
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('###########'),
            'celular' => fake()->numerify('###########'),
            'cidade' => fake()->city(),
            'uf' => fake()->stateAbbr(),
            'status' => 'ativo',
            'aliquota_iss' => 2.00,
            'prazo_pagamento_dias' => 15,
            'reter_iss' => false,
            'reter_pcc' => false,
            'reter_inss' => false,
            'reter_ir' => false,
        ];
    }
}
