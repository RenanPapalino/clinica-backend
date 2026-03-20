<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cadastros\CriarClienteAction;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\CpfCnpjService;
use App\Services\CnpjaService;
use App\Services\ViaCepService;
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

    public function store(Request $request, CriarClienteAction $criarClienteAction)
    {
        try {
            $data = $request->validate([
                'cnpj' => 'required|string',
                'razao_social' => 'required|string|max:200',
                'nome_fantasia' => 'nullable|string|max:200',
                'inscricao_municipal' => 'nullable|string|max:50',
                'inscricao_estadual' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:100',
                'telefone' => 'nullable|string|max:20',
                'celular' => 'nullable|string|max:20',
                'site' => 'nullable|string|max:255',
                'cep' => 'nullable|string|max:10',
                'logradouro' => 'nullable|string|max:255',
                'numero' => 'nullable|string|max:20',
                'complemento' => 'nullable|string|max:255',
                'bairro' => 'nullable|string|max:100',
                'cidade' => 'nullable|string|max:100',
                'uf' => 'nullable|string|max:2',
                'status' => 'nullable|in:ativo,inativo',
                'observacoes' => 'nullable|string|max:1000',
            ]);

            $cliente = $criarClienteAction->execute($data);

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
            $data = $request->validate([
                'cnpj' => 'sometimes|required|string',
                'razao_social' => 'sometimes|required|string|max:200',
                'nome_fantasia' => 'nullable|string|max:200',
                'inscricao_municipal' => 'nullable|string|max:50',
                'inscricao_estadual' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:100',
                'telefone' => 'nullable|string|max:20',
                'celular' => 'nullable|string|max:20',
                'site' => 'nullable|string|max:255',
                'cep' => 'nullable|string|max:10',
                'logradouro' => 'nullable|string|max:255',
                'numero' => 'nullable|string|max:20',
                'complemento' => 'nullable|string|max:255',
                'bairro' => 'nullable|string|max:100',
                'cidade' => 'nullable|string|max:100',
                'uf' => 'nullable|string|max:2',
                'status' => 'nullable|in:ativo,inativo',
                'observacoes' => 'nullable|string|max:1000',
            ]);

            $cliente->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Atualizado com sucesso',
                'data' => $cliente->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function consultarCnpj(Request $request, CnpjaService $cnpjaService)
    {
        $validated = $request->validate([
            'cnpj' => 'required|string',
        ]);

        $cnpj = preg_replace('/\D/', '', (string) $validated['cnpj']);

        if (strlen($cnpj) !== 14 || !Cliente::isValidCnpj($cnpj)) {
            return response()->json([
                'success' => false,
                'message' => 'Informe um CNPJ válido para consulta.',
            ], 422);
        }

        try {
            $result = $cnpjaService->consultarCnpj($cnpj);

            return response()->json([
                'success' => true,
                'message' => 'Consulta CNPJ realizada com sucesso.',
                'data' => $result['mapped'],
                'provider' => $result['provider'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar CNPJá', [
                'cnpj' => $cnpj,
                'message' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $status = str_contains(mb_strtolower($message), 'não foi possível') ? 502 : 422;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }
    }

    public function consultarCpf(Request $request, CpfCnpjService $cpfCnpjService)
    {
        $validated = $request->validate([
            'cpf' => 'required|string',
        ]);

        $cpf = preg_replace('/\D/', '', (string) $validated['cpf']);

        if (strlen($cpf) !== 11 || !Cliente::isValidCpf($cpf)) {
            return response()->json([
                'success' => false,
                'message' => 'Informe um CPF válido para consulta.',
            ], 422);
        }

        try {
            $result = $cpfCnpjService->consultarCpf($cpf);

            return response()->json([
                'success' => true,
                'message' => 'Consulta CPF realizada com sucesso.',
                'data' => $result['mapped'],
                'provider' => $result['provider'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar CPF', [
                'cpf' => $cpf,
                'message' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $status = str_contains(mb_strtolower($message), 'não foi possível') ? 502 : 422;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }
    }

    public function consultarCep(Request $request, ViaCepService $viaCepService)
    {
        $validated = $request->validate([
            'cep' => 'required|string',
        ]);

        $cep = preg_replace('/\D/', '', (string) $validated['cep']);

        if (strlen($cep) !== 8) {
            return response()->json([
                'success' => false,
                'message' => 'Informe um CEP válido para consulta.',
            ], 422);
        }

        try {
            $result = $viaCepService->consultarCep($cep);

            return response()->json([
                'success' => true,
                'message' => 'Consulta CEP realizada com sucesso.',
                'data' => $result['mapped'],
                'provider' => $result['provider'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar ViaCEP', [
                'cep' => $cep,
                'message' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $status = str_contains(mb_strtolower($message), 'não foi possível') ? 502 : 422;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
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
