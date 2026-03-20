<?php

namespace App\Actions\Financeiro;

use App\Models\Cliente;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Services\TributoService;
use Illuminate\Support\Facades\DB;

class CriarFaturaManualAction
{
    public function __construct(
        private readonly TributoService $tributoService,
    ) {
    }

    public function execute(array $data): Fatura
    {
        return DB::transaction(function () use ($data) {
            $cliente = Cliente::findOrFail($data['cliente_id']);
            $valorBruto = collect($data['itens'])->sum(
                fn (array $item) => $this->calcularValorItem($item)
            );

            $impostos = $this->tributoService->calcularRetencoes($valorBruto, $cliente);

            $fatura = Fatura::create([
                'cliente_id' => $cliente->id,
                'numero_fatura' => $this->gerarNumeroFatura(),
                'data_emissao' => $data['data_emissao'],
                'data_vencimento' => $data['data_vencimento'],
                'periodo_referencia' => $data['periodo_referencia'],
                'valor_servicos' => $valorBruto,
                'valor_total' => $impostos['valor_liquido'],
                'valor_iss' => $impostos['iss'],
                'status' => $data['status'] ?? 'pendente',
                'observacoes' => $data['observacoes'] ?? null,
            ]);

            foreach ($data['itens'] as $idx => $item) {
                FaturaItem::create([
                    'fatura_id' => $fatura->id,
                    'servico_id' => $item['servico_id'] ?? null,
                    'item_numero' => $idx + 1,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'valor_total' => $this->calcularValorItem($item),
                ]);
            }

            if (($data['gerar_titulo'] ?? true) === true) {
                $fatura->loadMissing('cliente');
                $fatura->gerarTituloPadrao();
            }

            return $fatura->fresh(['cliente', 'itens', 'titulos']);
        });
    }

    private function calcularValorItem(array $item): float
    {
        return (float) $item['quantidade'] * (float) $item['valor_unitario'];
    }

    private function gerarNumeroFatura(): string
    {
        return 'FAT-' . date('Ym') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}
