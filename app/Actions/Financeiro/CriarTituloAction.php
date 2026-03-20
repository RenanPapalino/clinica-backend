<?php

namespace App\Actions\Financeiro;

use App\Models\Titulo;
use Illuminate\Support\Facades\DB;

class CriarTituloAction
{
    public function execute(array $data): Titulo
    {
        $data['valor_juros'] = $data['valor_juros'] ?? 0;
        $data['valor_multa'] = $data['valor_multa'] ?? 0;
        $data['valor_desconto'] = $data['valor_desconto'] ?? 0;
        $data['valor_pago'] = $data['valor_pago'] ?? 0;

        $valorOriginal = (float) $data['valor_original'];
        $data['valor_saldo'] = $data['valor_saldo'] ?? $valorOriginal;
        $data['numero_titulo'] = $data['numero_titulo'] ?? ('TIT-' . time());

        return DB::transaction(function () use ($data, $valorOriginal) {
            $titulo = Titulo::create($data);

            if (!empty($data['rateios'])) {
                foreach ($data['rateios'] as $rateio) {
                    $valorRateio = (float) $rateio['valor'];

                    $titulo->rateios()->create([
                        'plano_conta_id' => $rateio['plano_conta_id'],
                        'centro_custo_id' => $rateio['centro_custo_id'] ?? null,
                        'valor' => $valorRateio,
                        'percentual' => $rateio['percentual'] ?? ($valorOriginal > 0 ? ($valorRateio / $valorOriginal) * 100 : null),
                        'historico' => $rateio['historico'] ?? 'Rateio automático na criação',
                    ]);
                }
            }

            return $titulo->fresh(['cliente', 'fornecedor', 'rateios']);
        });
    }
}
