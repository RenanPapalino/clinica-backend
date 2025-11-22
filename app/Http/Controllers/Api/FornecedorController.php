<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fornecedor;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'razao_social' => 'required|string',
            'nome_fantasia'=> 'nullable|string',
            'cnpj'         => 'required_without:cpf|nullable|string|unique:fornecedores,cnpj',
            'cpf'          => 'required_without:cnpj|nullable|string|unique:fornecedores,cpf',
            'email'        => 'nullable|email',
            'telefone'     => 'nullable|string',
            
            // Dados Bancários (Para arquivos de remessa futura)
            'banco_nome'   => 'nullable|string',
            'agencia'      => 'nullable|string',
            'conta'        => 'nullable|string',
            'ispb'         => 'nullable|string', // Manual cita ISPB para SISPAG Itaú
            
            // Flags Fiscais (Crucial para Contas a Pagar)
            // O sistema precisa saber se retém imposto na fonte
            'reter_iss'    => 'boolean',
            'reter_pcc'    => 'boolean', // PIS/COFINS/CSLL
            'reter_ir'     => 'boolean',
            'reter_inss'   => 'boolean',
        ]);

        // Limpeza de caracteres
        if(isset($data['cnpj'])) $data['cnpj'] = preg_replace('/\D/', '', $data['cnpj']);
        if(isset($data['cpf']))  $data['cpf']  = preg_replace('/\D/', '', $data['cpf']);

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
        $fornecedor->update($request->all());
        return response()->json(['success' => true, 'data' => $fornecedor]);
    }

    public function destroy($id)
    {
        Fornecedor::destroy($id);
        return response()->json(['success' => true]);
    }
}