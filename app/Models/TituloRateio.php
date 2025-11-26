<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TituloRateio extends Model
{
    use HasFactory;

    protected $fillable = [
        'titulo_id', 'centro_custo_id', 'plano_conta_id', 
        'cliente_id', 'valor', 'percentual', 'historico'
    ];

    public function titulo()
    {
        return $this->belongsTo(Titulo::class);
    }

    public function centroCusto()
    {
        return $this->belongsTo(CentroCusto::class);
    }

    public function planoConta()
    {
        return $this->belongsTo(PlanoConta::class);
    }
}