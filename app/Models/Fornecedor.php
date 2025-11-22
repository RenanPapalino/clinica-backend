<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Fornecedor extends Model
{
    use HasFactory;

    protected $table = 'fornecedores';

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'cpf', 
        'email', 'telefone', 'banco_nome', 'agencia', 
        'conta', 'ispb', 'status'
    ];

    // Um fornecedor tem muitos títulos a pagar
    public function titulos()
    {
        return $this->hasMany(Titulo::class, 'fornecedor_id');
    }
}