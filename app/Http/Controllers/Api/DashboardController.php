<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Titulo;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Títulos vencendo hoje/amanhã para o card "Títulos Vencendo Hoje"
     */
    public function titulosVencendo(Request $request)
    {
        $hoje = Carbon::today();
        $amanha = Carbon::tomorrow();

        $query = Titulo::query()
            ->with('cliente')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereBetween('data_vencimento', [$hoje, $amanha])
            ->orderBy('data_vencimento')
            ->orderBy('id');

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        $titulos = $query->limit(20)->get();

        $result = $titulos->map(function (Titulo $titulo) use ($hoje, $amanha) {
            $nomeCliente = optional($titulo->cliente)->nome_formatado
                ?? optional($titulo->cliente)->razao_social
                ?? 'Cliente';

            $saldo = $titulo->valor_saldo ?? $titulo->valor_original ?? 0;

            $dataVenc = $titulo->data_vencimento instanceof Carbon
                ? $titulo->data_vencimento
                : Carbon::parse($titulo->data_vencimento);

            if ($dataVenc->isSameDay($hoje)) {
                $vencStr = 'Hoje';
            } elseif ($dataVenc->isSameDay($amanha)) {
                $vencStr = 'Amanhã';
            } else {
                $vencStr = $dataVenc->format('d/m');
            }

            return [
                'cliente'    => $nomeCliente,
                'valor'      => 'R$ ' . number_format($saldo, 2, ',', '.'),
                'vencimento' => $vencStr,
            ];
        })->values();

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Ações pendentes para o card "Ações Pendentes"
     */
    public function acoesPendentes(Request $request)
    {
        $acoes = [];

        // 1) Faturas sem NFSe emitida
        $faturasSemNfse = Fatura::query()
            ->where('nfse_emitida', false)
            ->whereIn('status', ['aberta', 'emitida', 'fechada'])
            ->count();

        if ($faturasSemNfse > 0) {
            $acoes[] = [
                'texto'   => "{$faturasSemNfse} faturas sem NFSe emitida",
                'tipo'    => 'nfse',
                'urgency' => 'high',
            ];
        }

        // 2) Títulos vencidos (sem considerar pagos/cancelados)
        $titulosVencidos = Titulo::query()
            ->where('data_vencimento', '<', Carbon::today())
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->count();

        if ($titulosVencidos > 0) {
            $acoes[] = [
                'texto'   => "{$titulosVencidos} títulos vencidos em aberto",
                'tipo'    => 'cobranca',
                'urgency' => 'medium',
            ];
        }

        // 3) Clientes com títulos > 30 dias em atraso
        $limiteAtraso = Carbon::today()->subDays(30);

        $clientesEmAtraso = Cliente::query()
            ->whereHas('titulos', function ($q) use ($limiteAtraso) {
                $q->where('data_vencimento', '<=', $limiteAtraso)
                  ->whereNotIn('status', ['pago', 'cancelado']);
            })
            ->count();

        if ($clientesEmAtraso > 0) {
            $acoes[] = [
                'texto'   => "{$clientesEmAtraso} clientes com >30d atraso",
                'tipo'    => 'cliente',
                'urgency' => 'high',
            ];
        }

        return response()->json([
            'data' => $acoes,
        ]);
    }
}
