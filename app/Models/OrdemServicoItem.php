<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdemServicoItem extends Model
{
    protected $table = 'ordem_servico_itens';
    
    protected $fillable = [
        'ordem_servico_id', 'descricao', 'quantidade', 
        'valor_unitario', 'valor_total', 
        'unidade_soc', 'funcionario_soc', 'centro_custo_cliente'
    ];
}