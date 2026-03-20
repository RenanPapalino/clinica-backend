<?php

namespace App\Actions\Financeiro;

use App\Models\Despesa;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BaixarDespesaAction
{
    public function execute(
        int $despesaId,
        ?float $valor = null,
        mixed $dataPagamento = null,
    ): Despesa {
        return DB::transaction(function () use ($despesaId, $valor, $dataPagamento) {
            $despesa = Despesa::lockForUpdate()->findOrFail($despesaId);

            if ($despesa->status === 'pago') {
                throw new DomainException('Despesa já está paga.');
            }

            $valorDevido = (float) ($despesa->valor_original ?? $despesa->valor ?? 0);
            $valorInformado = $valor ?? $valorDevido;

            if ($valorInformado <= 0) {
                throw new DomainException('Valor de baixa inválido.');
            }

            $despesa->valor_pago = ((float) ($despesa->valor_pago ?? 0)) + $valorInformado;
            $despesa->data_pagamento = $dataPagamento
                ? Carbon::parse($dataPagamento)
                : now();

            if ((float) $despesa->valor_pago + 0.01 >= $valorDevido) {
                $despesa->status = 'pago';
                $despesa->valor_pago = $valorDevido;
            } else {
                $despesa->status = 'pendente';
            }

            $despesa->save();

            return $despesa->fresh(['fornecedor', 'categoria', 'planoConta']);
        });
    }
}
