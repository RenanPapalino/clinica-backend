<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Fornecedor extends Model
{
    use HasFactory;

    protected $table = 'fornecedores';

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'cpf',
        'email',
        'telefone',
        'site',
        'inscricao_estadual',
        'inscricao_municipal',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'observacoes',
        'banco_nome',
        'agencia',
        'conta',
        'ispb',
        'chave_pix',
        'dados_bancarios',
        'reter_iss',
        'reter_pcc',
        'reter_ir',
        'reter_inss',
        'status',
    ];

    protected $casts = [
        'reter_iss' => 'boolean',
        'reter_pcc' => 'boolean',
        'reter_ir' => 'boolean',
        'reter_inss' => 'boolean',
    ];

    // Um fornecedor tem muitos títulos a pagar
    public function titulos()
    {
        return $this->hasMany(Titulo::class, 'fornecedor_id');
    }
}
