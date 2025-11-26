<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Despesa extends Model
{
    use HasFactory;

    protected $fillable = [
        'fornecedor_id',
        'categoria_id',
        'descricao',
        'valor',
        'valor_original',
        'data_emissao',
        'data_vencimento',
        'data_pagamento',
        'valor_pago',
        'status',
        'documento_url',
        'documento_tipo',
        'codigo_barras',
        'observacoes',
        'plano_conta_id',
    ];

    protected $casts = [
        'data_emissao'    => 'date',
        'data_vencimento' => 'date',
        'data_pagamento'  => 'date',
        'valor'           => 'decimal:2',
        'valor_original'  => 'decimal:2',
        'valor_pago'      => 'decimal:2',
    ];

    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaDespesa::class, 'categoria_id');
    }

    public function planoConta()
    {
        return $this->belongsTo(PlanoConta::class, 'plano_conta_id');
    }

    public function lancamentosContabeis()
    {
        return $this->hasMany(LancamentoContabil::class, 'despesa_id');
    }
}
