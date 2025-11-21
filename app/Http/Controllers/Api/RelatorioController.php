<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RelatorioController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $mesAtual = now()->format('Y-m');

            $data = [
                'faturamento_mes' => Fatura::where('periodo_referencia', $mesAtual)->sum('valor_total'),
                'a_receber_total' => Titulo::where('status', '!=', 'pago')->sum('valor_saldo'),
                'vencidos_total' => Titulo::where('status', 'vencido')->sum('valor_saldo'),
                'titulos_abertos' => Titulo::where('status', 'aberto')->count(),
                'nfse_emitidas_mes' => Fatura::where('periodo_referencia', $mesAtual)->where('nfse_emitida', true)->count(),
            ];

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function faturamentoPorPeriodo(Request $request)
    {
        try {
            $dataInicio = $request->input('data_inicio', now()->subMonths(6)->startOfMonth());
            $dataFim = $request->input('data_fim', now()->endOfMonth());

            $faturamento = Fatura::selectRaw('periodo_referencia, SUM(valor_total) as total')
                ->whereBetween('data_emissao', [$dataInicio, $dataFim])
                ->groupBy('periodo_referencia')
                ->orderBy('periodo_referencia', 'asc')
                ->get();

            return response()->json(['success' => true, 'data' => $faturamento]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function topClientes(Request $request)
    {
        try {
            $limite = $request->input('limite', 10);

            $clientes = Fatura::select('cliente_id', DB::raw('SUM(valor_total) as total_faturado'))
                ->with('cliente')
                ->groupBy('cliente_id')
                ->orderBy('total_faturado', 'desc')
                ->limit($limite)
                ->get();

            return response()->json(['success' => true, 'data' => $clientes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
