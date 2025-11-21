<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $clientes = Cliente::orderBy('razao_social', 'asc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $clientes,
                'total' => $clientes->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar clientes',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Validação básica
            $data = $request->validate([
                'cnpj' => 'required|string',
                'razao_social' => 'required|string|max:200',
                'nome_fantasia' => 'nullable|string|max:200',
                'email' => 'nullable|email|max:100',
                'telefone' => 'nullable|string|max:20',
                'celular' => 'nullable|string|max:20',
                'cidade' => 'nullable|string|max:100',
                'uf' => 'nullable|string|max:2',
                'status' => 'nullable|in:ativo,inativo',
            ]);

            // Garantir status padrão
            if (!isset($data['status'])) {
                $data['status'] = 'ativo';
            }

            $cliente = Cliente::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Cliente cadastrado com sucesso',
                'data' => $cliente
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar cliente',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $cliente = Cliente::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $cliente
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente não encontrado'
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $cliente = Cliente::findOrFail($id);
            
            $data = $request->validate([
                'cnpj' => 'sometimes|string',
                'razao_social' => 'sometimes|string|max:200',
                'nome_fantasia' => 'nullable|string|max:200',
                'email' => 'nullable|email|max:100',
                'telefone' => 'nullable|string|max:20',
                'status' => 'nullable|in:ativo,inativo',
            ]);

            $cliente->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Cliente atualizado com sucesso',
                'data' => $cliente
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente não encontrado'
            ], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $cliente = Cliente::findOrFail($id);
            $cliente->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cliente excluído com sucesso'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente não encontrado'
            ], 404);
        }
    }

/**
     * Importar clientes em lote (para Agent/N8N)
     * 
     * Espera JSON no formato:
     * {
     *   "clientes": [
     *     { "cnpj": "11.222.333/0001-44", "razao_social": "...", "email": "...", ... },
     *     ...
     *   ]
     * }
     */
    public function importarLote(Request $request)
    {
        $data = $request->validate([
            'clientes' => 'required|array|min:1',
            'clientes.*.cnpj' => 'required|string',
            'clientes.*.razao_social' => 'required|string|max:200',
            'clientes.*.nome_fantasia' => 'nullable|string|max:200',
            'clientes.*.email' => 'nullable|email|max:100',
            'clientes.*.telefone' => 'nullable|string|max:20',
            'clientes.*.celular' => 'nullable|string|max:20',
            'clientes.*.cidade' => 'nullable|string|max:100',
            'clientes.*.uf' => 'nullable|string|max:2',
            'clientes.*.status' => 'nullable|in:ativo,inativo',
        ]);

        $importados = [];
        $atualizados = [];

        foreach ($data['clientes'] as $c) {
            // Normaliza CNPJ (só números)
            $cnpj = preg_replace('/\D/', '', $c['cnpj']);

            // Status padrão
            if (!isset($c['status'])) {
                $c['status'] = 'ativo';
            }

            $cliente = Cliente::where('cnpj', $cnpj)->first();

            if ($cliente) {
                $cliente->update(array_merge($c, ['cnpj' => $cnpj]));
                $atualizados[] = $cliente->id;
            } else {
                $novo = Cliente::create(array_merge($c, ['cnpj' => $cnpj]));
                $importados[] = $novo->id;
            }
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Importação de clientes concluída',
            'importados' => $importados,
            'atualizados'=> $atualizados,
            'total'      => count($importados) + count($atualizados),
        ]);
    }

}
