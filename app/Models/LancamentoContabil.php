<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LancamentoContabil extends Model
{
    use HasFactory;

    protected $table = 'lancamentos_contabeis';

    protected $fillable = [
        'data',
        'historico',
        'valor',
        'conta_debito_id',
        'conta_credito_id',
        'centro_custo_id',
        'despesa_id',
        'titulo_id',
        'origem',
        'status_ia',
        'usuario_id',
    ];

    protected $casts = [
        'data'  => 'date',
        'valor' => 'decimal:2',
    ];

    public function contaDebito()
    {
        return $this->belongsTo(PlanoConta::class, 'conta_debito_id');
    }

    public function contaCredito()
    {
        return $this->belongsTo(PlanoConta::class, 'conta_credito_id');
    }

    public function centroCusto()
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function despesa()
    {
        return $this->belongsTo(Despesa::class, 'despesa_id');
    }

    public function titulo()
    {
        return $this->belongsTo(Titulo::class, 'titulo_id');
    }
}
