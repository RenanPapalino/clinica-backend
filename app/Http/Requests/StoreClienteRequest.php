<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cnpj' => 'required|string|size:18|unique:clientes,cnpj',
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
            'uf' => 'nullable|string|size:2',
            'status' => 'nullable|in:ativo,inativo',
            'observacoes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.required' => 'O CNPJ é obrigatório.',
            'cnpj.size' => 'O CNPJ deve estar no formato 00.000.000/0000-00.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'razao_social.required' => 'A Razão Social é obrigatória.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('cnpj')) {
            $this->merge([
                'cnpj' => Cliente::formatarCNPJ((string) $this->input('cnpj')),
            ]);
        }

        if ($this->filled('uf')) {
            $this->merge([
                'uf' => mb_strtoupper((string) $this->input('uf')),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $cnpj = (string) $this->input('cnpj');

            if ($cnpj !== '' && !Cliente::validarCNPJ($cnpj)) {
                $validator->errors()->add('cnpj', 'CNPJ inválido.');
            }
        });
    }
}
