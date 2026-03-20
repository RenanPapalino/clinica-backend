<?php

namespace App\Actions\Cadastros;

use App\Models\Cliente;

class CriarClienteAction
{
    public function execute(array $data): Cliente
    {
        $data['cnpj'] = isset($data['cnpj']) ? preg_replace('/\D/', '', $data['cnpj']) : null;
        $data['status'] = $data['status'] ?? 'ativo';

        return Cliente::create($data);
    }
}
