<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cobranca extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cobrancas';

    protected $fillable = [
        'cliente_id',
        'fatura_id',
        'titulo_id',
        'meio',             // ex.: 'email', 'whatsapp', 'boleto', 'pix'
        'status',           // ex.: 'pendente', 'enviada', 'falha', 'paga'
        'canal',            // ex.: 'n8n', 'manual', 'api_banco'
        'descricao',
        'data_envio',
        'data_pagamento',
        'valor_cobrado',
    ];

    protected $casts = [
        'data_envio'      => 'datetime',
        'data_pagamento'  => 'datetime',
        'valor_cobrado'   => 'decimal:2',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELACIONAMENTOS
    |--------------------------------------------------------------------------
    */

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function fatura()
    {
        return $this->belongsTo(Fatura::class, 'fatura_id');
    }

    public function titulo()
    {
        return $this->belongsTo(Titulo::class, 'titulo_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopePendentes($query)
    {
        return $query->where('status', 'pendente');
    }

    public function scopeEnviadas($query)
    {
        return $query->where('status', 'enviada');
    }

    public function scopePagas($query)
    {
        return $query->where('status', 'paga');
    }

    public function scopePeriodoEnvio($query, ?string $inicio, ?string $fim)
    {
        if ($inicio) {
            $query->whereDate('data_envio', '>=', $inicio);
        }

        if ($fim) {
            $query->whereDate('data_envio', '<=', $fim);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getResumoAttribute(): string
    {
        $partes = [];

        if ($this->meio) {
            $partes[] = strtoupper($this->meio);
        }

        if ($this->status) {
            $partes[] = ucfirst($this->status);
        }

        if ($this->data_envio) {
            $partes[] = 'enviada em ' . $this->data_envio->format('d/m/Y H:i');
        }

        return implode(' • ', $partes);
    }

    /*
    |--------------------------------------------------------------------------
    | MUTATORS / HELPERS
    |--------------------------------------------------------------------------
    */

    public function setValorCobradoAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['valor_cobrado'] = null;
            return;
        }

        if (is_string($value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['valor_cobrado'] = (float) $value;
    }
}
