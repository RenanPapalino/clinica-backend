<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClienteRequest extends FormRequest
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
use Illuminate\Validation\Rule;
use App\Models\Cliente;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clienteId = $this->route('cliente');

        return [
            'cnpj' => [
                'sometimes',
                'required',
                'string',
                'size:18',
                Rule::unique('clientes', 'cnpj')->ignore($clienteId),
            ],
            'razao_social' => 'sometimes|required|string|max:200',
            'nome_fantasia' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:100',
            'status' => 'nullable|in:ativo,inativo',
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
}
