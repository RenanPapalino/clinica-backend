<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $query = Cliente::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('razao_social', 'like', "%{$search}%")
                  ->orWhere('nome_fantasia', 'like', "%{$search}%")
                  ->orWhere('cnpj', 'like', "%{$search}%");
            });
        }

        $clientes = $query->orderBy('razao_social', 'asc')->paginate(50);
        
        return response()->json([
            'success' => true,
            'data' => $clientes
        ]);
    }

    public function store(Request $request)
    {
        try {
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

            // Limpa CNPJ
            $data['cnpj'] = preg_replace('/\D/', '', $data['cnpj']);
            $data['status'] = $data['status'] ?? 'ativo';

            $cliente = Cliente::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Cliente cadastrado com sucesso',
                'data' => $cliente
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $cliente = Cliente::findOrFail($id);
        return response()->json(['success' => true, 'data' => $cliente]);
    }

    public function update(Request $request, $id)
    {
        try {
            $cliente = Cliente::findOrFail($id);
            $cliente->update($request->all());
            return response()->json(['success' => true, 'message' => 'Atualizado com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Cliente::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Removido com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Método crucial para a Importação via Planilha/IA
     */
    public function confirmarImportacao(Request $request)
    {
        Log::info('Iniciando importação de clientes', ['qtd' => count($request->input('clientes', []))]);

        try {
            $dados = $request->input('clientes');
            
            if (empty($dados) || !is_array($dados)) {
                return response()->json(['success' => false, 'message' => 'Nenhum dado recebido.'], 400);
            }

            $stats = ['criados' => 0, 'atualizados' => 0, 'erros' => 0];

            DB::beginTransaction();

            foreach ($dados as $c) {
                // Validação mínima: precisa de nome
                if (empty($c['razao_social'])) continue;

                // Limpa CNPJ para garantir unicidade (somente números)
                $cnpjLimpo = isset($c['cnpj']) ? preg_replace('/\D/', '', $c['cnpj']) : null;
                
                // Prepara o array de dados
                $clienteData = [
                    'razao_social'  => mb_strtoupper(trim($c['razao_social'])),
                    'nome_fantasia' => isset($c['nome_fantasia']) ? mb_strtoupper(trim($c['nome_fantasia'])) : null,
                    'email'         => isset($c['email']) ? strtolower(trim($c['email'])) : null,
                    'telefone'      => $c['telefone'] ?? null,
                    'cnpj'          => $cnpjLimpo,
                    'cidade'        => $c['cidade'] ?? null,
                    'uf'            => $c['uf'] ?? null,
                    'status'        => 'ativo'
                ];

                // Tenta encontrar cliente existente
                $cliente = null;
                if (!empty($cnpjLimpo)) {
                    $cliente = Cliente::where('cnpj', $cnpjLimpo)->first();
                } 
                
                // Se não achou por CNPJ (ou não tem CNPJ), tenta por Razão Social exata
                if (!$cliente) {
                    $cliente = Cliente::where('razao_social', $clienteData['razao_social'])->first();
                }

                if ($cliente) {
                    $cliente->update($clienteData);
                    $stats['atualizados']++;
                } else {
                    Cliente::create($clienteData);
                    $stats['criados']++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Importação finalizada: {$stats['criados']} novos, {$stats['atualizados']} atualizados.",
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro Importação Cliente: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()], 500);
        }
    }
}