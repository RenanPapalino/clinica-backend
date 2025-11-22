<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Titulo;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RelatorioController extends Controller
{
    /**
     * Painel geral de indicadores
     * Rota: GET /relatorios/dashboard
     */
    public function dashboard(Request $request)
    {
        $hoje = Carbon::today();
        $inicioMes = $hoje->copy()->startOfMonth();
        $fimMes = $hoje->copy()->endOfMonth();

        // Total a receber (títulos em aberto)
        $totalAReceber = (float) Titulo::query()
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->sum(DB::raw('COALESCE(valor_saldo, valor_original)'));

        // Faturamento do mês (faturas emitidas no mês)
        $faturamentoMes = (float) Fatura::query()
            ->whereBetween('data_emissao', [$inicioMes, $fimMes])
            ->sum('valor_total');

        // Recebido no mês (títulos pagos no mês)
        $recebidoMes = (float) Titulo::query()
            ->whereNotNull('data_pagamento')
            ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
            ->sum('valor_pago');

        // Taxa de liquidação no mês
        $taxaLiquidacao = $faturamentoMes > 0
            ? round(($recebidoMes / $faturamentoMes) * 100, 2)
            : 0;

        // Série de recebimentos últimos 30 dias (para gráfico)
        $inicioJanela = $hoje->copy()->subDays(29);

        $recebimentos = Titulo::query()
            ->selectRaw('DATE(data_pagamento) as data, SUM(valor_pago) as total')
            ->whereNotNull('data_pagamento')
            ->whereBetween('data_pagamento', [$inicioJanela, $hoje])
            ->groupBy(DB::raw('DATE(data_pagamento)'))
            ->orderBy('data')
            ->get()
            ->map(function ($row) {
                return [
                    'date'  => Carbon::parse($row->data)->format('d/m'),
                    'valor' => (float) $row->total,
                ];
            })
            ->values();

        return response()->json([
            'kpis' => [
                'total_a_receber' => $totalAReceber,
                'faturamento_mes' => $faturamentoMes,
                'recebido_mes'    => $recebidoMes,
                'taxa_liquidacao' => $taxaLiquidacao,
            ],
            'recebimentos_30d' => $recebimentos,
        ]);
    }

    /**
     * Faturamento por período (pode ser diário ou mensal)
     * Rota: GET /relatorios/faturamento-periodo?inicio=YYYY-MM-DD&fim=YYYY-MM-DD&agrupamento=mensal|diario
     */
    public function faturamentoPorPeriodo(Request $request)
    {
        $inicio = $request->get('inicio');
        $fim = $request->get('fim');
        $agrupamento = $request->get('agrupamento', 'mensal'); // 'mensal' ou 'diario'

        $query = Fatura::query()
            ->whereNotIn('status', ['cancelada']);

        if ($inicio) {
            $query->whereDate('data_emissao', '>=', $inicio);
        }

        if ($fim) {
            $query->whereDate('data_emissao', '<=', $fim);
        }

        if ($agrupamento === 'diario') {
            $query->selectRaw('DATE(data_emissao) as periodo, SUM(valor_total) as total')
                  ->groupBy(DB::raw('DATE(data_emissao)'))
                  ->orderBy('periodo');
        } else {
            // mensal
            $query->selectRaw("DATE_FORMAT(data_emissao, '%Y-%m') as periodo, SUM(valor_total) as total")
                  ->groupBy(DB::raw("DATE_FORMAT(data_emissao, '%Y-%m')"))
                  ->orderBy('periodo');
        }

        $rows = $query->get()->map(function ($row) use ($agrupamento) {
            $label = $row->periodo;

            if ($agrupamento === 'diario') {
                $label = Carbon::parse($row->periodo)->format('d/m/Y');
            } else {
                $label = Carbon::parse($row->periodo . '-01')->format('m/Y');
            }

            return [
                'periodo' => $label,
                'total'   => (float) $row->total,
            ];
        });

        return response()->json([
            'data' => $rows,
        ]);
    }

    /**
     * Top clientes por faturamento
     * Rota: GET /relatorios/top-clientes?limit=5
     */
    public function topClientes(Request $request)
    {
        $limit = (int) ($request->get('limit', 5));

        $rows = Fatura::query()
            ->join('clientes', 'clientes.id', '=', 'faturas.cliente_id')
            ->selectRaw('clientes.id, clientes.razao_social, clientes.nome_fantasia, SUM(faturas.valor_total) as total')
            ->whereNotIn('faturas.status', ['cancelada'])
            ->groupBy('clientes.id', 'clientes.razao_social', 'clientes.nome_fantasia')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'cliente_id'     => $row->id,
                    'nome'           => $row->nome_fantasia ?? $row->razao_social,
                    'valor_total'    => (float) $row->total,
                ];
            });

        return response()->json([
            'data' => $rows,
        ]);
    }
}
