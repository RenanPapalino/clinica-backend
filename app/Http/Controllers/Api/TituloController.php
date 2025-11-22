<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TituloController extends Controller
{
    /**
     * Lista de títulos (com filtros)
     */
    public function index(Request $request)
    {
        $query = Titulo::with(['cliente', 'fatura'])
            ->orderBy('data_vencimento')
            ->orderBy('id');

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('vencimento_de')) {
            $query->whereDate('data_vencimento', '>=', $request->vencimento_de);
        }

        if ($request->filled('vencimento_ate')) {
            $query->whereDate('data_vencimento', '<=', $request->vencimento_ate);
        }

        $perPage = (int) ($request->get('per_page', 20));

        $titulos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $titulos,
        ]);
    }

    /**
     * Cria um novo título
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'      => ['required', 'exists:clientes,id'],
            'fatura_id'       => ['nullable', 'exists:faturas,id'],
            'numero_titulo'   => ['required', 'string', 'max:50'],
            'nosso_numero'    => ['nullable', 'string', 'max:50'],
            'data_emissao'    => ['required', 'date'],
            'data_vencimento' => ['required', 'date'],
            'valor_original'  => ['required', 'numeric', 'min:0'],
            'valor_juros'     => ['nullable', 'numeric', 'min:0'],
            'valor_multa'     => ['nullable', 'numeric', 'min:0'],
            'valor_desconto'  => ['nullable', 'numeric', 'min:0'],
            'status'          => ['required', 'string', 'max:20'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'codigo_barras'   => ['nullable', 'string', 'max:255'],
            'linha_digitavel' => ['nullable', 'string', 'max:255'],
            'url_boleto'      => ['nullable', 'string', 'max:255'],
            'observacoes'     => ['nullable', 'string'],
        ]);

        $data['valor_juros']    = $data['valor_juros']    ?? 0;
        $data['valor_multa']    = $data['valor_multa']    ?? 0;
        $data['valor_desconto'] = $data['valor_desconto'] ?? 0;
        $data['valor_pago']     = 0;
        $data['valor_saldo']    = $data['valor_original'];

        $titulo = Titulo::create($data);

        return response()->json([
            'success' => true,
            'data' => $titulo->fresh(['cliente', 'fatura']),
        ], 201);
    }

    /**
     * Detalhe do título
     */
    public function show($id)
    {
        $titulo = Titulo::with(['cliente', 'fatura', 'cobrancas'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $titulo,
        ]);
    }

    /**
     * Atualiza título
     */
    public function update(Request $request, $id)
    {
        $titulo = Titulo::findOrFail($id);

        $data = $request->validate([
            'data_vencimento' => ['sometimes', 'date'],
            'data_pagamento'  => ['nullable', 'date'],
            'valor_original'  => ['sometimes', 'numeric', 'min:0'],
            'valor_juros'     => ['nullable', 'numeric', 'min:0'],
            'valor_multa'     => ['nullable', 'numeric', 'min:0'],
            'valor_desconto'  => ['nullable', 'numeric', 'min:0'],
            'valor_pago'      => ['nullable', 'numeric', 'min:0'],
            'status'          => ['sometimes', 'string', 'max:20'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'observacoes'     => ['nullable', 'string'],
        ]);

        $titulo->fill($data);

        if ($titulo->isDirty([
            'valor_original',
            'valor_juros',
            'valor_multa',
            'valor_desconto',
            'valor_pago',
        ])) {
            $titulo->recalcularSaldo();
        } else {
            $titulo->save();
        }

        return response()->json([
            'success' => true,
            'data' => $titulo->fresh(['cliente', 'fatura']),
        ]);
    }

    /**
     * Remove (soft delete)
     */
    public function destroy($id)
    {
        $titulo = Titulo::findOrFail($id);
        $titulo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Título removido com sucesso.',
        ]);
    }

    /**
     * Baixar título (registrar pagamento)
     * POST /contas-receber/titulos/{id}/baixar
     */
    public function baixar(Request $request, $id)
    {
        $titulo = Titulo::findOrFail($id);

        $data = $request->validate([
            'valor'           => ['required', 'numeric', 'min:0.01'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'data_pagamento'  => ['nullable', 'date'],
        ]);

        $titulo->registrarPagamento(
            (float) $data['valor'],
            $data['forma_pagamento'] ?? null
        );

        if (!empty($data['data_pagamento'])) {
            $titulo->data_pagamento = $data['data_pagamento'];
            $titulo->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Título baixado com sucesso.',
            'data' => $titulo->fresh(),
        ]);
    }

    /**
     * Relatório de aging
     * GET /contas-receber/aging
     */
    public function relatorioAging(Request $request)
    {
        $hoje = Carbon::today();

        $base = Titulo::query()
            ->whereNotIn('status', ['pago', 'cancelado']);

        if ($request->filled('cliente_id')) {
            $base->where('cliente_id', $request->cliente_id);
        }

        $titulos = $base->get();

        $buckets = [
            'atual'    => 0.0,
            'ate_30'   => 0.0,
            'de_31_60' => 0.0,
            'de_61_90' => 0.0,
            'acima_90' => 0.0,
        ];

        foreach ($titulos as $titulo) {
            $saldo = (float) ($titulo->valor_saldo ?? $titulo->valor_original ?? 0);

            if ($saldo <= 0) {
                continue;
            }

            $venc = $titulo->data_vencimento instanceof Carbon
                ? $titulo->data_vencimento
                : Carbon::parse($titulo->data_vencimento);

            $dias = $venc->diffInDays($hoje, false);

            if ($dias <= 0) {
                $buckets['atual'] += $saldo;
            } elseif ($dias <= 30) {
                $buckets['ate_30'] += $saldo;
            } elseif ($dias <= 60) {
                $buckets['de_31_60'] += $saldo;
            } elseif ($dias <= 90) {
                $buckets['de_61_90'] += $saldo;
            } else {
                $buckets['acima_90'] += $saldo;
            }
        }

        $response = [];
        foreach ($buckets as $faixa => $valor) {
            $response[] = [
                'faixa' => $faixa,
                'valor' => round($valor, 2),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $response,
        ]);
    }
}
