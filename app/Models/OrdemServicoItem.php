<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdemServicoItem extends Model
{
    protected $table = 'ordem_servico_itens';
    
    // ADICIONE 'centro_custo' e 'data_realizacao' AQUI NA LISTA:
    protected $fillable = [
        'ordem_servico_id',
        'descricao',
        'quantidade',
        'valor_unitario',
        'valor_total',
        'centro_custo',    // <--- IMPORTANTE
        'data_realizacao'  // <--- IMPORTANTE
    ];
}