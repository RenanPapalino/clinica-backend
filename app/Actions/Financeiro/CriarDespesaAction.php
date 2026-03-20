<?php

namespace App\Actions\Financeiro;

use App\Models\Despesa;
use App\Models\LancamentoContabil;
use Illuminate\Support\Facades\DB;

class CriarDespesaAction
{
    public function execute(array $data, ?int $userId = null): Despesa
    {
        return DB::transaction(function () use ($data, $userId) {
            $valorOriginal = $data['valor_original'] ?? $data['valor'] ?? 0;
            $data['valor_original'] = $valorOriginal;
            $data['valor'] = $valorOriginal;
            $data['data_emissao'] = $data['data_emissao'] ?? now();
            $data['status'] = $data['status'] ?? 'pendente';

            $despesa = Despesa::create($data);
            $rateios = $data['rateios'] ?? [];

            $contaFornecedorPadrao = config('contabilidade.conta_fornecedores_padrao');
            if (!$contaFornecedorPadrao && !empty($data['plano_conta_id'])) {
                $contaFornecedorPadrao = $data['plano_conta_id'];
            }

            if (!empty($rateios)) {
                foreach ($rateios as $rateio) {
                    LancamentoContabil::create([
                        'data' => $despesa->data_emissao ?? $despesa->data_vencimento,
                        'historico' => $despesa->descricao,
                        'valor' => $rateio['valor'],
                        'conta_debito_id' => $rateio['plano_conta_id'],
                        'conta_credito_id' => $contaFornecedorPadrao,
                        'centro_custo_id' => $rateio['centro_custo_id'] ?? null,
                        'despesa_id' => $despesa->id,
                        'origem' => 'contas_pagar',
                        'status_ia' => 'sugerido',
                        'usuario_id' => $userId,
                    ]);
                }
            } elseif (!empty($data['plano_conta_id']) && $valorOriginal > 0) {
                LancamentoContabil::create([
                    'data' => $despesa->data_emissao ?? $despesa->data_vencimento,
                    'historico' => $despesa->descricao,
                    'valor' => $valorOriginal,
                    'conta_debito_id' => $data['plano_conta_id'],
                    'conta_credito_id' => $contaFornecedorPadrao,
                    'centro_custo_id' => null,
                    'despesa_id' => $despesa->id,
                    'origem' => 'contas_pagar',
                    'status_ia' => 'sugerido',
                    'usuario_id' => $userId,
                ]);
            }

            return $despesa->fresh(['fornecedor', 'categoria', 'planoConta']);
        });
    }
}
