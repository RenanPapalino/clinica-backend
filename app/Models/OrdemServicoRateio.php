<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdemServicoRateio extends Model
{
    protected $table = 'ordem_servico_rateios';
    protected $fillable = [
        'ordem_servico_id', 
        'centro_custo', 
        'valor', 
        'percentual'
    ];
}