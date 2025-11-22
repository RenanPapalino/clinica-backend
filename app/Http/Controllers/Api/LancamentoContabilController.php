<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LancamentoContabil;
use App\Services\Financeiro\ContabilidadeInteligenteService;
use Illuminate\Http\Request;

class LancamentoContabilController extends Controller
{
    // Lista o Livro Diário (tudo que aconteceu)
    public function index(Request $request)
    {
        $query = LancamentoContabil::with(['contaDebito', 'contaCredito']);

        if ($request->filled('inicio') && $request->filled('fim')) {
            $query->whereBetween('data_lancamento', [$request->inicio, $request->fim]);
        }

        // Filtro por conta (Livro Razão)
        if ($request->filled('conta_id')) {
            $contaId = $request->conta_id;
            $query->where(function($q) use ($contaId) {
                $q->where('conta_debito_id', $contaId)
                  ->orWhere('conta_credito_id', $contaId);
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('data_lancamento')->paginate(50)
        ]);
    }

    // Endpoint para processar manualmente um título antigo que não foi contabilizado
    public function processarTitulo(Request $request, $id, ContabilidadeInteligenteService $service)
    {
        $titulo = \App\Models\Titulo::findOrFail($id);
        $lancamento = $service->gerarLancamentoAutomatico($titulo);

        if ($lancamento) {
            return response()->json(['success' => true, 'message' => 'Contabilizado com sucesso', 'data' => $lancamento]);
        } else {
            return response()->json(['success' => false, 'message' => 'IA não conseguiu classificar. Faça o lançamento manual.'], 422);
        }
    }
}