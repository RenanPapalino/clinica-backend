<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Titulo;
use App\Models\Cliente;
use App\Models\Fatura;
use App\Models\Nfse;
use App\Models\Despesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Retorna KPIs completos do dashboard
     */
    public function kpisCompletos()
    {
        $hoje = Carbon::now();
        $inicioMes = Carbon::now()->startOfMonth();
        $fimMes = Carbon::now()->endOfMonth();

        // Total a Receber (Próximos 30 dias)
        $totalReceber = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->whereBetween('data_vencimento', [$hoje, $hoje->copy()->addDays(30)])
            ->sum('valor_saldo');

        // Total Vencido
        $totalVencido = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', $hoje)
            ->sum('valor_saldo');

        // Faturas Emitidas (Este Mês)
        $faturasEmitidas = Fatura::whereBetween('created_at', [$inicioMes, $fimMes])->count();

        // Clientes Ativos
        $clientesAtivos = Cliente::where('ativo', true)->count();

        // Receita do Mês
        $receitaMes = Titulo::where('tipo', 'receber')
            ->where('status', 'pago')
            ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
            ->sum('valor_pago');

        // Despesas do Mês
        $despesasMes = Despesa::whereBetween('data_vencimento', [$inicioMes, $fimMes])
            ->sum('valor_original');

        // Lucro Líquido
        $lucroLiquido = $receitaMes - $despesasMes;

        return response()->json([
            'success' => true,
            'data' => [
                'total_receber' => floatval($totalReceber),
                'total_vencido' => floatval($totalVencido),
                'faturas_emitidas' => $faturasEmitidas,
                'clientes_ativos' => $clientesAtivos,
                'receita_mes' => floatval($receitaMes),
                'despesas_mes' => floatval($despesasMes),
                'lucro_liquido' => floatval($lucroLiquido)
            ]
        ]);
    }

    /**
     * Retorna títulos vencendo nos próximos dias
     */
    public function titulosVencendo(Request $request)
    {
        $dias = $request->input('dias', 7);
        $hoje = Carbon::now();
        
        $titulos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->whereBetween('data_vencimento', [$hoje, $hoje->copy()->addDays($dias)])
            ->with('cliente')
            ->orderBy('data_vencimento')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $titulos
        ]);
    }

    /**
     * Retorna ações pendentes
     */
    public function acoesPendentes()
    {
        $hoje = Carbon::now();
        
        // Títulos vencidos
        $titulosVencidos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', $hoje)
            ->count();

        // Notas fiscais pendentes
        $nfsesPendentes = Nfse::where('status', 'pendente')->count();

        // Faturas não emitidas
        $faturasNaoEmitidas = Fatura::whereNull('numero_nfse')->count();

        // Despesas a pagar (próximos 7 dias)
        $despesasVencendo = Despesa::where('status', '!=', 'pago')
            ->whereBetween('data_vencimento', [$hoje, $hoje->copy()->addDays(7)])
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'titulos_vencidos' => $titulosVencidos,
                'nfses_pendentes' => $nfsesPendentes,
                'faturas_nao_emitidas' => $faturasNaoEmitidas,
                'despesas_vencendo' => $despesasVencendo
            ]
        ]);
    }

    /**
     * Retorna últimas faturas
     */
    public function ultimasFaturas()
    {
        $faturas = Fatura::with('cliente')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $faturas
        ]);
    }

    /**
     * Retorna dados para gráfico de fluxo de caixa
     */
    public function fluxoCaixa(Request $request)
    {
        $meses = $request->input('meses', 12);
        $dataInicio = Carbon::now()->subMonths($meses)->startOfMonth();
        $dataFim = Carbon::now()->endOfMonth();

        $dados = [];
        
        for ($i = 0; $i < $meses; $i++) {
            $mesAtual = Carbon::now()->subMonths($meses - $i - 1);
            $inicioMes = $mesAtual->copy()->startOfMonth();
            $fimMes = $mesAtual->copy()->endOfMonth();

            // Entradas (Títulos Pagos)
            $entradas = Titulo::where('tipo', 'receber')
                ->where('status', 'pago')
                ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
                ->sum('valor_pago');

            // Saídas (Despesas Pagas)
            $saidas = Despesa::where('status', 'pago')
                ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
                ->sum('valor_pago');

            $dados[] = [
                'mes' => $mesAtual->format('M/y'),
                'mes_completo' => $mesAtual->translatedFormat('F Y'),
                'entradas' => floatval($entradas),
                'saidas' => floatval($saidas),
                'saldo' => floatval($entradas - $saidas)
            ];
        }

        // Calcula acumulado
        $acumulado = 0;
        foreach ($dados as &$d) {
            $acumulado += $d['saldo'];
            $d['acumulado'] = $acumulado;
        }

        return response()->json([
            'success' => true,
            'data' => $dados
        ]);
    }

    /**
     * Retorna dados consolidados para todos os gráficos
     */
    public function graficos(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'kpis' => $this->kpisCompletos()->getData()->data,
                'fluxo_caixa' => $this->fluxoCaixa($request)->getData()->data,
                'ultimas_faturas' => $this->ultimasFaturas()->getData()->data,
                'acoes_pendentes' => $this->acoesPendentes()->getData()->data
            ]
        ]);
    }

    /**
     * Retorna top clientes por faturamento
     */
    public function topClientes(Request $request)
    {
        $limite = $request->input('limite', 10);
        $meses = $request->input('meses', 3);
        
        $dataInicio = Carbon::now()->subMonths($meses);

        $topClientes = Cliente::select('clientes.*')
            ->selectRaw('SUM(titulos.valor_original) as total_faturado')
            ->join('titulos', 'clientes.id', '=', 'titulos.cliente_id')
            ->where('titulos.tipo', 'receber')
            ->where('titulos.created_at', '>=', $dataInicio)
            ->groupBy('clientes.id')
            ->orderByDesc('total_faturado')
            ->limit($limite)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $topClientes
        ]);
    }

    /**
     * Retorna distribuição de receitas por serviço
     */
    public function receitaPorServico(Request $request)
    {
        $meses = $request->input('meses', 1);
        $dataInicio = Carbon::now()->subMonths($meses);

        $distribuicao = DB::table('fatura_itens')
            ->join('servicos', 'fatura_itens.servico_id', '=', 'servicos.id')
            ->join('faturas', 'fatura_itens.fatura_id', '=', 'faturas.id')
            ->select('servicos.descricao', DB::raw('SUM(fatura_itens.valor_total) as total'))
            ->where('faturas.created_at', '>=', $dataInicio)
            ->groupBy('servicos.id', 'servicos.descricao')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $distribuicao
        ]);
    }

    /**
     * Retorna taxa de inadimplência
     */
    public function taxaInadimplencia()
    {
        $hoje = Carbon::now();

        $totalTitulos = Titulo::where('tipo', 'receber')
            ->where('data_vencimento', '<', $hoje)
            ->count();

        $titulosVencidos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', $hoje)
            ->count();

        $taxa = $totalTitulos > 0 ? ($titulosVencidos / $totalTitulos) * 100 : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_titulos' => $totalTitulos,
                'titulos_vencidos' => $titulosVencidos,
                'taxa_inadimplencia' => round($taxa, 2)
            ]
        ]);
    }
}
