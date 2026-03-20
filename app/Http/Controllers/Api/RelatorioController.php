<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Despesa;
use App\Models\Fatura;
use App\Models\Titulo;
use App\Models\Cliente;
use Barryvdh\DomPDF\Facade\Pdf;
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

    /**
     * Fluxo de caixa realizado com base em recebimentos e pagamentos efetivos.
     */
    public function fluxoCaixaReal(Request $request)
    {
        [$inicio, $fim] = $this->resolvePeriodo(
            $request->input('inicio'),
            $request->input('fim'),
            true
        );

        $agrupamento = $request->input('agrupamento', 'mensal') === 'diario'
            ? 'diario'
            : 'mensal';

        $selectPeriodo = $agrupamento === 'diario'
            ? "DATE(%s)"
            : "DATE_FORMAT(%s, '%Y-%m')";

        $entradas = Titulo::query()
            ->selectRaw(sprintf($selectPeriodo, 'data_pagamento') . ' as periodo')
            ->selectRaw('SUM(COALESCE(valor_pago, valor_original, 0)) as total')
            ->where('tipo', 'receber')
            ->where('status', 'pago')
            ->whereNotNull('data_pagamento')
            ->whereBetween('data_pagamento', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('periodo')
            ->pluck('total', 'periodo');

        $saidas = Despesa::query()
            ->selectRaw(sprintf($selectPeriodo, 'data_pagamento') . ' as periodo')
            ->selectRaw('SUM(COALESCE(valor_pago, valor_original, valor, 0)) as total')
            ->where('status', 'pago')
            ->whereNotNull('data_pagamento')
            ->whereBetween('data_pagamento', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('periodo')
            ->pluck('total', 'periodo');

        $cursor = $agrupamento === 'diario'
            ? $inicio->copy()
            : $inicio->copy()->startOfMonth();
        $fimIteracao = $agrupamento === 'diario'
            ? $fim->copy()
            : $fim->copy()->startOfMonth();

        $serie = [];
        $saldoAcumulado = 0.0;

        while ($cursor->lte($fimIteracao)) {
            $chave = $agrupamento === 'diario'
                ? $cursor->format('Y-m-d')
                : $cursor->format('Y-m');

            $entradasPeriodo = (float) ($entradas[$chave] ?? 0);
            $saidasPeriodo = (float) ($saidas[$chave] ?? 0);
            $saldoPeriodo = $entradasPeriodo - $saidasPeriodo;
            $saldoAcumulado += $saldoPeriodo;

            $serie[] = [
                'periodo' => $agrupamento === 'diario'
                    ? $cursor->format('d/m/Y')
                    : $cursor->format('m/Y'),
                'entradas' => $entradasPeriodo,
                'saidas' => $saidasPeriodo,
                'saldo_periodo' => $saldoPeriodo,
                'saldo_acumulado' => $saldoAcumulado,
            ];

            $cursor = $agrupamento === 'diario'
                ? $cursor->addDay()
                : $cursor->addMonthNoOverflow();
        }

        return response()->json([
            'data' => $serie,
            'resumo' => [
                'total_entradas' => round(array_sum(array_column($serie, 'entradas')), 2),
                'total_saidas' => round(array_sum(array_column($serie, 'saidas')), 2),
                'saldo_liquido' => round(array_sum(array_column($serie, 'saldo_periodo')), 2),
            ],
            'competencia' => [
                'inicio' => $inicio->toDateString(),
                'fim' => $fim->toDateString(),
                'agrupamento' => $agrupamento,
            ],
        ]);
    }

    /**
     * DRE gerencial simplificado.
     */
    public function dreReal(Request $request)
    {
        [$inicio, $fim] = $this->resolvePeriodo(
            $request->input('inicio'),
            $request->input('fim'),
            true
        );

        $faturas = Fatura::query()
            ->whereNotIn('status', ['cancelada'])
            ->whereBetween('data_emissao', [$inicio->toDateString(), $fim->toDateString()]);

        $receitaServicos = (float) (clone $faturas)->sum(DB::raw('COALESCE(valor_servicos, valor_total, 0)'));
        $acrescimos = (float) (clone $faturas)->sum(DB::raw('COALESCE(valor_acrescimos, 0)'));
        $descontos = (float) (clone $faturas)->sum(DB::raw('COALESCE(valor_descontos, 0)'));
        $iss = (float) (clone $faturas)->sum(DB::raw('COALESCE(valor_iss, 0)'));

        $despesasOperacionais = (float) Despesa::query()
            ->where('status', '!=', 'cancelado')
            ->where(function ($query) use ($inicio, $fim) {
                $query->whereBetween('data_emissao', [$inicio->toDateString(), $fim->toDateString()])
                    ->orWhere(function ($fallback) use ($inicio, $fim) {
                        $fallback->whereNull('data_emissao')
                            ->whereBetween('data_vencimento', [$inicio->toDateString(), $fim->toDateString()]);
                    });
            })
            ->sum(DB::raw('COALESCE(valor_original, valor, 0)'));

        $receitaBruta = $receitaServicos + $acrescimos;
        $deducoes = $descontos + $iss;
        $receitaLiquida = $receitaBruta - $deducoes;
        $resultadoOperacional = $receitaLiquida - $despesasOperacionais;
        $margemResultado = $receitaLiquida !== 0.0
            ? round(($resultadoOperacional / $receitaLiquida) * 100, 2)
            : 0.0;

        $linhas = [
            [
                'grupo' => 'receita',
                'label' => 'Receita de servicos',
                'valor' => round($receitaServicos, 2),
            ],
            [
                'grupo' => 'receita',
                'label' => 'Acrescimos',
                'valor' => round($acrescimos, 2),
            ],
            [
                'grupo' => 'deducao',
                'label' => 'Descontos concedidos',
                'valor' => round($descontos, 2),
            ],
            [
                'grupo' => 'deducao',
                'label' => 'ISS',
                'valor' => round($iss, 2),
            ],
            [
                'grupo' => 'resultado',
                'label' => 'Receita liquida',
                'valor' => round($receitaLiquida, 2),
            ],
            [
                'grupo' => 'despesa',
                'label' => 'Despesas operacionais',
                'valor' => round($despesasOperacionais, 2),
            ],
            [
                'grupo' => 'resultado',
                'label' => 'Resultado operacional',
                'valor' => round($resultadoOperacional, 2),
            ],
        ];

        return response()->json([
            'competencia' => [
                'inicio' => $inicio->toDateString(),
                'fim' => $fim->toDateString(),
            ],
            'resumo' => [
                'receita_bruta' => round($receitaBruta, 2),
                'deducoes' => round($deducoes, 2),
                'receita_liquida' => round($receitaLiquida, 2),
                'despesas_operacionais' => round($despesasOperacionais, 2),
                'resultado_operacional' => round($resultadoOperacional, 2),
                'margem_resultado' => $margemResultado,
            ],
            'linhas' => $linhas,
        ]);
    }

    /**
     * Exporta PDF gerencial simples do DRE ou do fluxo de caixa.
     */
    public function exportarPdf(Request $request)
    {
        $tipo = $request->input('tipo', 'dre');

        if ($tipo === 'fluxo-caixa') {
            $payload = $this->fluxoCaixaReal($request)->getData(true);
            $html = $this->renderFluxoCaixaPdf($payload);
            $filename = 'fluxo-caixa-real-' . now()->format('Y-m-d') . '.pdf';
        } else {
            $payload = $this->dreReal($request)->getData(true);
            $html = $this->renderDrePdf($payload);
            $filename = 'dre-gerencial-' . now()->format('Y-m-d') . '.pdf';
        }

        return Pdf::loadHTML($html)
            ->setPaper('a4')
            ->download($filename);
    }

    private function resolvePeriodo(?string $inicio, ?string $fim, bool $fallbackYear = false): array
    {
        $inicioCarbon = $inicio
            ? Carbon::parse($inicio)->startOfDay()
            : ($fallbackYear ? now()->startOfYear()->startOfDay() : now()->startOfMonth()->startOfDay());
        $fimCarbon = $fim
            ? Carbon::parse($fim)->endOfDay()
            : now()->endOfMonth()->endOfDay();

        if ($inicioCarbon->gt($fimCarbon)) {
            [$inicioCarbon, $fimCarbon] = [$fimCarbon->copy()->startOfDay(), $inicioCarbon->copy()->endOfDay()];
        }

        return [$inicioCarbon, $fimCarbon];
    }

    private function renderDrePdf(array $payload): string
    {
        $linhas = collect($payload['linhas'] ?? [])
            ->map(function (array $linha) {
                $valor = number_format((float) ($linha['valor'] ?? 0), 2, ',', '.');

                return '<tr>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;">' . e($linha['label'] ?? '') . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">R$ ' . $valor . '</td>'
                    . '</tr>';
            })
            ->implode('');

        $resumo = $payload['resumo'] ?? [];
        $inicio = $payload['competencia']['inicio'] ?? '';
        $fim = $payload['competencia']['fim'] ?? '';

        return '
            <html>
                <body style="font-family: DejaVu Sans, sans-serif; color:#0f172a;">
                    <h1 style="margin-bottom:4px;">DRE Gerencial</h1>
                    <p style="margin-top:0;color:#475569;">Periodo: ' . e($inicio) . ' a ' . e($fim) . '</p>
                    <table style="width:100%;border-collapse:collapse;margin-top:24px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;padding:8px;border-bottom:2px solid #cbd5e1;">Conta</th>
                                <th style="text-align:right;padding:8px;border-bottom:2px solid #cbd5e1;">Valor</th>
                            </tr>
                        </thead>
                        <tbody>' . $linhas . '</tbody>
                    </table>
                    <div style="margin-top:24px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                        <strong>Resultado operacional:</strong> R$ ' . number_format((float) ($resumo['resultado_operacional'] ?? 0), 2, ',', '.') . '<br>
                        <strong>Margem:</strong> ' . number_format((float) ($resumo['margem_resultado'] ?? 0), 2, ',', '.') . '%
                    </div>
                </body>
            </html>
        ';
    }

    private function renderFluxoCaixaPdf(array $payload): string
    {
        $linhas = collect($payload['data'] ?? [])
            ->map(function (array $linha) {
                return '<tr>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;">' . e($linha['periodo'] ?? '') . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">R$ ' . number_format((float) ($linha['entradas'] ?? 0), 2, ',', '.') . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">R$ ' . number_format((float) ($linha['saidas'] ?? 0), 2, ',', '.') . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;">R$ ' . number_format((float) ($linha['saldo_periodo'] ?? 0), 2, ',', '.') . '</td>'
                    . '</tr>';
            })
            ->implode('');

        $inicio = $payload['competencia']['inicio'] ?? '';
        $fim = $payload['competencia']['fim'] ?? '';
        $resumo = $payload['resumo'] ?? [];

        return '
            <html>
                <body style="font-family: DejaVu Sans, sans-serif; color:#0f172a;">
                    <h1 style="margin-bottom:4px;">Fluxo de Caixa Realizado</h1>
                    <p style="margin-top:0;color:#475569;">Periodo: ' . e($inicio) . ' a ' . e($fim) . '</p>
                    <table style="width:100%;border-collapse:collapse;margin-top:24px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;padding:8px;border-bottom:2px solid #cbd5e1;">Periodo</th>
                                <th style="text-align:right;padding:8px;border-bottom:2px solid #cbd5e1;">Entradas</th>
                                <th style="text-align:right;padding:8px;border-bottom:2px solid #cbd5e1;">Saidas</th>
                                <th style="text-align:right;padding:8px;border-bottom:2px solid #cbd5e1;">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>' . $linhas . '</tbody>
                    </table>
                    <div style="margin-top:24px;padding:16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
                        <strong>Total de entradas:</strong> R$ ' . number_format((float) ($resumo['total_entradas'] ?? 0), 2, ',', '.') . '<br>
                        <strong>Total de saidas:</strong> R$ ' . number_format((float) ($resumo['total_saidas'] ?? 0), 2, ',', '.') . '<br>
                        <strong>Saldo liquido:</strong> R$ ' . number_format((float) ($resumo['saldo_liquido'] ?? 0), 2, ',', '.') . '
                    </div>
                </body>
            </html>
        ';
    }
}
