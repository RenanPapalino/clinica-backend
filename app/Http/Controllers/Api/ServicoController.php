<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServicoController extends Controller
{
    public function index(Request $request)
    {
        $query = Servico::query();

        if ($request->has('search')) {
            $termo = $request->search;
            $query->where(function($q) use ($termo) {
                $q->where('descricao', 'like', "%{$termo}%")
                  ->orWhere('codigo', 'like', "%{$termo}%")
                  ->orWhere('codigo_servico_municipal', 'like', "%{$termo}%");
            });
        }

        return response()->json([
            'success' => true, 
            'data' => $query->orderBy('descricao')->get()
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'codigo' => 'required|string|unique:servicos,codigo', // Código interno
                'descricao' => 'required|string|max:255',
                'valor_unitario' => 'required|numeric|min:0',
                'tipo_servico' => 'required|string', // ex: exame, consulta
                
                // Campos Fiscais (NFS-e)
                'codigo_servico_municipal' => 'nullable|string', // LC116 (Ex: 04.03)
                'cnae' => 'nullable|string',
                'aliquota_iss' => 'nullable|numeric',
                'ativo' => 'boolean'
            ]);

            // Define ativo como true se não vier
            $validated['ativo'] = $validated['ativo'] ?? true;

            $servico = Servico::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Serviço cadastrado com sucesso',
                'data' => $servico
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error("Erro ao criar serviço: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $servico = Servico::findOrFail($id);
            
            $validated = $request->validate([
                'codigo' => 'required|string|unique:servicos,codigo,'.$id,
                'descricao' => 'required|string|max:255',
                'valor_unitario' => 'required|numeric|min:0',
                'tipo_servico' => 'required|string',
                'codigo_servico_municipal' => 'nullable|string',
                'cnae' => 'nullable|string',
                'aliquota_iss' => 'nullable|numeric',
                'ativo' => 'boolean'
            ]);

            $servico->update($validated);

            return response()->json(['success' => true, 'data' => $servico]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $servico = Servico::findOrFail($id);
            $servico->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}