<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fornecedor;
use App\Services\CnpjaService;
use App\Services\ViaCepService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FornecedorController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Fornecedor::orderBy('razao_social')->get()
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeDocumentFields($request);

        $data = $request->validate([
            'razao_social' => 'required|string|max:255',
            'nome_fantasia'=> 'nullable|string|max:255',
            'cnpj'         => 'required_without:cpf|nullable|string|unique:fornecedores,cnpj',
            'cpf'          => 'required_without:cnpj|nullable|string|unique:fornecedores,cpf',
            'email'        => 'nullable|email|max:255',
            'telefone'     => 'nullable|string|max:30',
            'site'         => 'nullable|string|max:255',
            'inscricao_estadual' => 'nullable|string|max:50',
            'inscricao_municipal' => 'nullable|string|max:50',
            'cep'          => 'nullable|string|max:10',
            'logradouro'   => 'nullable|string|max:255',
            'numero'       => 'nullable|string|max:20',
            'complemento'  => 'nullable|string|max:255',
            'bairro'       => 'nullable|string|max:100',
            'cidade'       => 'nullable|string|max:100',
            'uf'           => 'nullable|string|max:2',
            'observacoes'  => 'nullable|string|max:1000',
            'banco_nome'   => 'nullable|string|max:100',
            'agencia'      => 'nullable|string|max:50',
            'conta'        => 'nullable|string|max:50',
            'ispb'         => 'nullable|string|max:20',
            'chave_pix'    => 'nullable|string|max:255',
            'dados_bancarios' => 'nullable|string|max:2000',
            'reter_iss'    => 'boolean',
            'reter_pcc'    => 'boolean',
            'reter_ir'     => 'boolean',
            'reter_inss'   => 'boolean',
            'status'       => 'nullable|in:ativo,inativo',
        ]);

        $fornecedor = Fornecedor::create($data);

        return response()->json(['success' => true, 'data' => $fornecedor], 201);
    }

    public function show($id)
    {
        return response()->json(['success' => true, 'data' => Fornecedor::findOrFail($id)]);
    }

    public function update(Request $request, $id)
    {
        $fornecedor = Fornecedor::findOrFail($id);
        $this->normalizeDocumentFields($request);

        $data = $request->validate([
            'razao_social' => 'sometimes|required|string|max:255',
            'nome_fantasia'=> 'nullable|string|max:255',
            'cnpj'         => ['required_without:cpf', 'nullable', 'string', Rule::unique('fornecedores', 'cnpj')->ignore($fornecedor->id)],
            'cpf'          => ['required_without:cnpj', 'nullable', 'string', Rule::unique('fornecedores', 'cpf')->ignore($fornecedor->id)],
            'email'        => 'nullable|email|max:255',
            'telefone'     => 'nullable|string|max:30',
            'site'         => 'nullable|string|max:255',
            'inscricao_estadual' => 'nullable|string|max:50',
            'inscricao_municipal' => 'nullable|string|max:50',
            'cep'          => 'nullable|string|max:10',
            'logradouro'   => 'nullable|string|max:255',
            'numero'       => 'nullable|string|max:20',
            'complemento'  => 'nullable|string|max:255',
            'bairro'       => 'nullable|string|max:100',
            'cidade'       => 'nullable|string|max:100',
            'uf'           => 'nullable|string|max:2',
            'observacoes'  => 'nullable|string|max:1000',
            'banco_nome'   => 'nullable|string|max:100',
            'agencia'      => 'nullable|string|max:50',
            'conta'        => 'nullable|string|max:50',
            'ispb'         => 'nullable|string|max:20',
            'chave_pix'    => 'nullable|string|max:255',
            'dados_bancarios' => 'nullable|string|max:2000',
            'reter_iss'    => 'boolean',
            'reter_pcc'    => 'boolean',
            'reter_ir'     => 'boolean',
            'reter_inss'   => 'boolean',
            'status'       => 'nullable|in:ativo,inativo',
        ]);

        $fornecedor->update($data);
        return response()->json(['success' => true, 'data' => $fornecedor]);
    }

    public function consultarCnpj(Request $request, CnpjaService $cnpjaService)
    {
        $validated = $request->validate([
            'cnpj' => 'required|string',
        ]);

        $cnpj = preg_replace('/\D/', '', (string) $validated['cnpj']);

        if (strlen($cnpj) !== 14) {
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
            Log::warning('Falha ao consultar CNPJá para fornecedor', [
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
            Log::warning('Falha ao consultar ViaCEP para fornecedor', [
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
        Fornecedor::destroy($id);
        return response()->json(['success' => true]);
    }

    private function normalizeDocumentFields(Request $request): void
    {
        $request->merge([
            'cnpj' => $request->filled('cnpj') ? preg_replace('/\D/', '', (string) $request->input('cnpj')) : null,
            'cpf' => $request->filled('cpf') ? preg_replace('/\D/', '', (string) $request->input('cpf')) : null,
            'cep' => $request->filled('cep') ? preg_replace('/\D/', '', (string) $request->input('cep')) : null,
            'uf' => $request->filled('uf') ? mb_strtoupper((string) $request->input('uf')) : null,
        ]);
    }
}
