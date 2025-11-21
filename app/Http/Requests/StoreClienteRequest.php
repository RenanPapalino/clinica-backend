<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Cliente;

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
            'email' => 'nullable|email|max:100',
            'telefone' => 'nullable|string|max:20',
            'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|size:2',
            'status' => 'nullable|in:ativo,inativo',
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.required' => 'O CNPJ é obrigatório',
            'cnpj.unique' => 'Este CNPJ já está cadastrado',
            'razao_social.required' => 'A Razão Social é obrigatória',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cnpj')) {
            $this->merge([
                'cnpj' => Cliente::formatarCNPJ($this->cnpj),
            ]);
        }
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('cnpj') && !Cliente::validarCNPJ($this->cnpj)) {
                $validator->errors()->add('cnpj', 'CNPJ inválido');
            }
        });
    }
}
