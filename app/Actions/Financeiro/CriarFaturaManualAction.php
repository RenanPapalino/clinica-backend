<?php

namespace App\Actions\Financeiro;

use App\Models\Cliente;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Services\TributoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            $colunasFaturas = array_flip(Schema::getColumnListing('faturas'));
            $faturaData = [
                'cliente_id' => $cliente->id,
                'numero_fatura' => $this->gerarNumeroFatura(),
                'data_emissao' => $data['data_emissao'],
                'data_vencimento' => $data['data_vencimento'],
                'periodo_referencia' => $data['periodo_referencia'],
                'valor_servicos' => $valorBruto,
                'valor_total' => $impostos['valor_liquido'],
                'valor_iss' => $impostos['iss'],
                'status' => $this->resolveStatus($data['status'] ?? 'aberta'),
                'observacoes' => $data['observacoes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ];

            $faturaData = array_intersect_key($faturaData, $colunasFaturas);

            $fatura = Fatura::create($faturaData);
            $colunasFaturaItens = array_flip(Schema::getColumnListing('fatura_itens'));

            foreach ($data['itens'] as $idx => $item) {
                $itemData = array_intersect_key([
                    'fatura_id' => $fatura->id,
                    'servico_id' => $item['servico_id'] ?? null,
                    'item_numero' => $idx + 1,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'valor_total' => $this->calcularValorItem($item),
                ], $colunasFaturaItens);

                FaturaItem::create($itemData);
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

    private function resolveStatus(string $status): string
    {
        try {
            $coluna = collect(DB::select("SHOW COLUMNS FROM faturas WHERE Field = 'status'"))->first();
            if ($coluna && isset($coluna->Type) && str_starts_with($coluna->Type, 'enum(')) {
                preg_match_all("/'([^']+)'/", $coluna->Type, $matches);
                $permitidos = $matches[1] ?? [];

                if (in_array($status, $permitidos, true)) {
                    return $status;
                }

                foreach (['aberta', 'pendente', 'aberto', 'emitida'] as $candidato) {
                    if (in_array($candidato, $permitidos, true)) {
                        return $candidato;
                    }
                }

                return $permitidos[0] ?? $status;
            }
        } catch (\Throwable $e) {
            // Se não conseguir ler metadata da coluna, mantém o valor informado.
        }

        return $status;
    }
}
