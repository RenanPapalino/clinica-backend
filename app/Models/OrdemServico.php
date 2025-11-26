<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdemServico extends Model
{
    protected $table = 'ordens_servico';
    
    protected $fillable = [
        'cliente_id', 'codigo_os', 'competencia', 
        'data_emissao', 'valor_total', 'status', 
        'fatura_gerada_id', 'observacoes'
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'valor_total' => 'decimal:2'
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens()
    {
        return $this->hasMany(OrdemServicoItem::class);
    }

    public function fatura()
    {
        return $this->belongsTo(Fatura::class, 'fatura_gerada_id');
    }
}