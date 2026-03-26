<?php

namespace App\Actions\Financeiro;

use App\Models\Titulo;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RenegociarTituloAction
{
    public function execute(
        int $tituloId,
        string $novaDataVencimento,
        ?string $observacoes = null,
    ): Titulo {
        return DB::transaction(function () use ($tituloId, $novaDataVencimento, $observacoes) {
            $titulo = Titulo::lockForUpdate()->findOrFail($tituloId);
            $novaData = Carbon::parse($novaDataVencimento)->toDateString();

            if ($titulo->status === 'pago') {
                throw new DomainException('Título já está pago e não pode ser renegociado.');
            }

            $titulo->data_vencimento = $novaData;
            $titulo->status = 'aberto';

            if ($observacoes) {
                $historicoAtual = trim((string) ($titulo->observacoes ?? ''));
                $notaRenegociacao = trim($observacoes);
                $titulo->observacoes = trim($historicoAtual . "\n" . $notaRenegociacao);
            }

            $titulo->save();

            if ($titulo->tipo === 'receber' && $titulo->fatura_id) {
                $titulo->fatura()->update([
                    'data_vencimento' => $novaData,
                ]);
            }

            return $titulo->fresh(['cliente', 'fornecedor', 'fatura']);
        });
    }
}
