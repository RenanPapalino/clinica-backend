<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CentroCustoController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => CentroCusto::orderBy('descricao')->get()
        ]);
    }

    public function store(Request $request)
    {
        Log::info('Tentativa de criar Centro de Custo:', $request->all());

        try {
            // Validação
            $validated = $request->validate([
                'codigo' => 'required|string|unique:centros_custo,codigo',
                'descricao' => 'required|string', // Frontend envia 'descricao'
                'ativo' => 'boolean'
            ]);

            // Criação
            $centro = CentroCusto::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Centro de custo criado com sucesso',
                'data' => $centro
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro Validação Centro Custo: ' . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro Interno Centro Custo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar no banco: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $centro = CentroCusto::findOrFail($id);

            $validated = $request->validate([
                'codigo' => 'required|string|unique:centros_custo,codigo,' . $id,
                'descricao' => 'required|string',
                'ativo' => 'boolean'
            ]);

            $centro->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Centro de custo atualizado',
                'data' => $centro
            ]);
        } catch (\Exception $e) {
            Log::error('Erro Update Centro Custo: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $centro = CentroCusto::findOrFail($id);
            $centro->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }
}