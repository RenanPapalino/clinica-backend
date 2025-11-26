<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanoConta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Adicionado para debug

class PlanoContaController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => PlanoConta::orderBy('codigo')->get()
        ]);
    }

    public function store(Request $request)
    {
        // Loga o que chegou para facilitar o debug no storage/logs/laravel.log
        Log::info('Payload PlanoConta:', $request->all());

        // Normalização de dados antes da validação
        $data = $request->all();
        
        // Garante que conta_pai_id seja nulo se for 0 ou vazio
        if (empty($data['conta_pai_id']) || $data['conta_pai_id'] === '0') {
            $data['conta_pai_id'] = null;
        }

        // Validação robusta
        $validated = validator($data, [
            'codigo' => 'required|string|unique:planos_contas,codigo',
            'descricao' => 'required|string',
            'tipo' => 'required|in:receita,despesa',
            'natureza' => 'nullable|string', // Aceita string genérica por enquanto para não travar
            'analitica' => 'boolean',
            'conta_contabil' => 'nullable|string',
            'conta_pai_id' => 'nullable|exists:planos_contas,id',
            'ativo' => 'boolean'
        ])->validate();

        try {
            $plano = PlanoConta::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Conta criada com sucesso',
                'data' => $plano
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao criar Plano de Conta: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao salvar: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $plano = PlanoConta::findOrFail($id);
        
        $data = $request->all();
        if (empty($data['conta_pai_id']) || $data['conta_pai_id'] === '0') {
            $data['conta_pai_id'] = null;
        }

        // Validação ignorando o ID atual na unique
        $validated = validator($data, [
            'codigo' => 'required|string|unique:planos_contas,codigo,' . $id,
            'descricao' => 'required|string',
            'tipo' => 'required|in:receita,despesa',
            'natureza' => 'nullable|string',
            'analitica' => 'boolean',
            'conta_contabil' => 'nullable|string',
            'conta_pai_id' => 'nullable|exists:planos_contas,id',
            'ativo' => 'boolean'
        ])->validate();

        $plano->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Conta atualizada com sucesso',
            'data' => $plano
        ]);
    }

    public function destroy($id)
    {
        $plano = PlanoConta::findOrFail($id);
        
        if ($plano->filhos()->exists()) {
            return response()->json(['success' => false, 'message' => 'Não é possível excluir conta com subcontas.'], 400);
        }

        $plano->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conta removida com sucesso'
        ]);
    }
}