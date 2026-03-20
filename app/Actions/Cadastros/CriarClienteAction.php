<?php

namespace App\Actions\Cadastros;

use App\Models\Cliente;
use Illuminate\Support\Facades\Schema;

class CriarClienteAction
{
    public function execute(array $data): Cliente
    {
        $data['cnpj'] = isset($data['cnpj']) ? preg_replace('/\D/', '', $data['cnpj']) : null;
        $data['status'] = $data['status'] ?? 'ativo';
        $data = $this->adaptarPayloadAoSchemaAtual($data);

        return Cliente::create($data);
    }

    private function adaptarPayloadAoSchemaAtual(array $data): array
    {
        if (!Schema::hasTable('clientes')) {
            return $data;
        }

        $colunas = array_flip(Schema::getColumnListing('clientes'));

        if (!isset($colunas['logradouro']) && isset($colunas['endereco']) && !empty($data['logradouro'])) {
            $data['endereco'] = $data['logradouro'];
            unset($data['logradouro']);
        }

        return array_intersect_key($data, $colunas);
    }
}
