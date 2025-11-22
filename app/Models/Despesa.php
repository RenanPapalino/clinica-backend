<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Despesa extends Model
{
    use HasFactory;

    protected $fillable = [
        'fornecedor_id', 'categoria_id', 'descricao', 'valor', 
        'data_emissao', 'data_vencimento', 'data_pagamento', 
        'valor_pago', 'status', 'documento_url', 'documento_tipo', 
        'codigo_barras', 'observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'data_pagamento' => 'date',
        'valor' => 'decimal:2',
        'valor_pago' => 'decimal:2',
    ];

    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaDespesa::class, 'categoria_id');
    }
}