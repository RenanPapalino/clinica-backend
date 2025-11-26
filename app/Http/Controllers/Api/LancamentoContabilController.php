<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LancamentoContabil;
use Illuminate\Http\Request;

class LancamentoContabilController extends Controller
{
    public function index(Request $request)
    {
        $query = LancamentoContabil::with([
            'contaDebito',
            'contaCredito',
            'centroCusto',
            'despesa',
        ]);

        if ($request->filled('inicio')) {
            $query->whereDate('data', '>=', $request->inicio);
        }

        if ($request->filled('fim')) {
            $query->whereDate('data', '<=', $request->fim);
        }

        if ($request->filled('conta_id')) {
            $contaId = $request->conta_id;
            $query->where(function ($q) use ($contaId) {
                $q->where('conta_debito_id', $contaId)
                  ->orWhere('conta_credito_id', $contaId);
            });
        }

        if ($request->filled('origem')) {
            $query->where('origem', $request->origem);
        }

        $lancamentos = $query
            ->orderBy('data')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $lancamentos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'data'             => 'required|date',
            'historico'        => 'required|string',
            'valor'            => 'required|numeric',
            'conta_debito_id'  => 'required|exists:planos_contas,id',
            'conta_credito_id' => 'required|exists:planos_contas,id',
            'centro_custo_id'  => 'nullable|exists:centros_custo,id',
            'despesa_id'       => 'nullable|exists:despesas,id',
            'titulo_id'        => 'nullable|integer',
            'origem'           => 'nullable|string|max:50',
            'status_ia'        => 'nullable|string|max:30',
        ]);

        $data['usuario_id'] = $request->user()->id ?? null;

        $lancamento = LancamentoContabil::create($data);

        return response()->json([
            'success' => true,
            'data'    => $lancamento,
        ], 201);
    }
}
