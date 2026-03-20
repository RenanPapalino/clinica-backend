<?php

namespace App\Http\Controllers\Api;

use App\Actions\Financeiro\CriarDespesaAction;
use App\Http\Controllers\Controller;
use App\Models\Despesa;
use App\Models\Fornecedor;
use App\Services\Ai\DocumentReaderService;
use Illuminate\Http\Request;

class DespesaController extends Controller
{
    public function index(Request $request)
    {
        $query = Despesa::with(['fornecedor', 'categoria', 'planoConta']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('inicio')) {
            $query->whereDate('data_vencimento', '>=', $request->inicio);
        }

        if ($request->filled('fim')) {
            $query->whereDate('data_vencimento', '<=', $request->fim);
        }

        $despesas = $query
            ->orderBy('data_vencimento')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $despesas,
        ]);
    }

    public function store(Request $request, CriarDespesaAction $criarDespesaAction)
    {
        $data = $request->validate([
            'descricao'        => 'required|string',
            'valor'            => 'nullable|numeric',
            'valor_original'   => 'nullable|numeric',
            'data_vencimento'  => 'required|date',
            'data_emissao'     => 'nullable|date',
            'fornecedor_id'    => 'nullable|exists:fornecedores,id',
            'categoria_id'     => 'nullable|exists:categorias_despesa,id',
            'documento_url'    => 'nullable|string',
            'observacoes'      => 'nullable|string',
            'codigo_barras'    => 'nullable|string',
            'status'           => 'nullable|in:pendente,pago,atrasado,cancelado',
            'plano_conta_id'   => 'nullable|exists:planos_contas,id',

            // rateios vindos do front (RateioForm)
            'rateios'                      => 'nullable|array',
            'rateios.*.plano_conta_id'     => 'required_with:rateios|exists:planos_contas,id',
            'rateios.*.centro_custo_id'    => 'nullable|exists:centros_custo,id',
            'rateios.*.percentual'         => 'nullable|numeric',
            'rateios.*.valor'              => 'required_with:rateios|numeric',
        ]);

        $despesa = $criarDespesaAction->execute($data, $request->user()->id ?? null);

        return response()->json([
            'success' => true,
            'data'    => $despesa->load(['fornecedor', 'categoria', 'planoConta']),
        ], 201);
    }

public function analisarDocumento(Request $request, DocumentReaderService $ocrService)
{
    $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png']);

    $file = $request->file('file');
    // Salva temporariamente para leitura
    $path = $file->store('temp_analise');
    $fullPath = storage_path('app/' . $path);

    try {
        // 1. Chamada à IA
        $jsonString = $ocrService->lerDocumento($fullPath);
        $dados = json_decode($jsonString, true);

        // 2. Verificação de Duplicidade (Modernização)
        $duplicado = false;
        if (!empty($dados['codigo_barras'])) {
            $duplicado = Despesa::where('codigo_barras', $dados['codigo_barras'])->exists();
        }

        // 3. Cruzamento com Fornecedor existente
        $fornecedor = null;
        if (!empty($dados['cnpj_fornecedor'])) {
            $fornecedor = Fornecedor::where('cnpj', $dados['cnpj_fornecedor'])->first();
        }

        // Remove arquivo temporário após leitura
        unlink($fullPath); 
        
        // Salva arquivo definitivo se o usuário confirmar no front, 
        // mas aqui retornamos a URL temporária ou fazemos o upload definitivo depois.
        // Para simplificar, vamos assumir que o front fará o upload real no 'store'.
        
        return response()->json([
            'success'         => true,
            'dados_sugeridos' => [
                'valor'           => $dados['valor_total'],
                'data_vencimento' => $dados['data_vencimento'],
                'data_emissao'    => $dados['data_emissao'],
                'descricao'       => $dados['descricao'],
                'codigo_barras'   => $dados['codigo_barras'],
                'fornecedor_id'   => $fornecedor?->id,
                'nome_fornecedor' => $fornecedor?->razao_social ?? $dados['nome_fornecedor'],
                'alerta_duplicidade' => $duplicado
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
    public function pagar($id, Request $request)
    {
        $despesa = Despesa::findOrFail($id);

        $valorBaixa = $request->input('valor', 0);
        if ($valorBaixa <= 0) {
            $valorBaixa = $despesa->valor_original ?? $despesa->valor;
        }

        $despesa->update([
            'status'         => 'pago',
            'data_pagamento' => $request->input('data_pagamento', now()),
            'valor_pago'     => $valorBaixa,
        ]);

        // (Opcional) aqui futuramente você pode gerar o lançamento de baixa:
        // débito Fornecedores / crédito Bancos.

        return response()->json([
            'success' => true,
            'message' => 'Despesa paga',
        ]);
    }
}
