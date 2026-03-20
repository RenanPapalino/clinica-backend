<?php

namespace App\Actions\Financeiro;

use DomainException;
use App\Models\Titulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BaixarTituloAction
{
    public function execute(
        int $tituloId,
        float $valor,
        ?string $formaPagamento = null,
        mixed $dataPagamento = null,
    ): Titulo {
        return DB::transaction(function () use ($tituloId, $valor, $formaPagamento, $dataPagamento) {
            $titulo = Titulo::lockForUpdate()->findOrFail($tituloId);

            if ($titulo->status === 'pago') {
                throw new DomainException('Título já está pago.');
            }

            $titulo->valor_pago = ((float) $titulo->valor_pago) + $valor;

            $totalDevido = (
                (float) $titulo->valor_original
                + (float) ($titulo->valor_juros ?? 0)
                + (float) ($titulo->valor_multa ?? 0)
            ) - (float) ($titulo->valor_desconto ?? 0);

            $titulo->valor_saldo = max(0, $totalDevido - (float) $titulo->valor_pago);
            $titulo->status = $titulo->valor_saldo <= 0.01 ? 'pago' : 'parcial';

            if ($titulo->status === 'pago') {
                $titulo->valor_saldo = 0;
            }

            if ($formaPagamento !== null) {
                $titulo->forma_pagamento = $formaPagamento;
            }

            $titulo->data_pagamento = $dataPagamento
                ? Carbon::parse($dataPagamento)
                : now();

            $titulo->save();

            return $titulo->fresh();
        });
    }
}
