<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Titulo;
use Illuminate\Http\Request;

class TituloController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Titulo::with(['cliente', 'fatura']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            $titulos = $query->orderBy('data_vencimento', 'desc')->get();

            return response()->json(['success' => true, 'data' => $titulos]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'cliente_id' => 'required|exists:clientes,id',
                'fatura_id' => 'nullable|exists:faturas,id',
                'data_vencimento' => 'required|date',
                'valor_original' => 'required|numeric',
            ]);

            $titulo = Titulo::create([
                'cliente_id' => $validated['cliente_id'],
                'fatura_id' => $validated['fatura_id'] ?? null,
                'numero_titulo' => 'TIT-' . date('Ym') . '-' . str_pad((Titulo::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT),
                'data_emissao' => now(),
                'data_vencimento' => $validated['data_vencimento'],
                'valor_original' => $validated['valor_original'],
                'valor_saldo' => $validated['valor_original'],
                'status' => 'aberto',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Título criado com sucesso',
                'data' => $titulo
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $titulo = Titulo::with(['cliente', 'fatura'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $titulo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Título não encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $titulo = Titulo::findOrFail($id);
            $titulo->update($request->all());
            return response()->json(['success' => true, 'data' => $titulo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Titulo::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Título excluído']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function baixar(Request $request, $id)
    {
        try {
            $titulo = Titulo::findOrFail($id);
            
            $titulo->update([
                'status' => 'pago',
                'data_pagamento' => now(),
                'valor_saldo' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Título baixado com sucesso',
                'data' => $titulo
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function relatorioAging()
    {
        try {
            $titulos = Titulo::with('cliente')
                ->where('status', '!=', 'pago')
                ->orderBy('data_vencimento', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $titulos,
                'total' => $titulos->count()
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
