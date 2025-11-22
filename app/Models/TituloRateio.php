<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TituloRateio extends Model
{
    use HasFactory;

    protected $fillable = [
        'titulo_id', 'plano_conta_id', 'centro_custo_id', 
        'valor', 'percentual', 'historico'
    ];

    public function planoConta()
    {
        return $this->belongsTo(PlanoConta::class);
    }

    public function centroCusto()
    {
        return $this->belongsTo(CentroCusto::class);
    }
}