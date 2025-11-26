<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fatura extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'faturas';

    protected $fillable = [
        'cliente_id',
        'numero_fatura',
        'data_emissao',
        'data_vencimento',
        'periodo_referencia',
        'valor_servicos',
        'valor_descontos',
        'valor_acrescimos',
        'valor_iss',
        'valor_total',
        'status',        // ex.: 'aberta', 'fechada', 'emitida', 'cancelada'
        'nfse_emitida',  // boolean
        'observacoes',
        'metadata',
        'nfse_numero',
        'nfse_link',
    ];

    protected $casts = [
        'data_emissao'      => 'date',
        'data_vencimento'   => 'date',
        'valor_servicos'    => 'decimal:2',
        'valor_descontos'   => 'decimal:2',
        'valor_acrescimos'  => 'decimal:2',
        'valor_iss'         => 'decimal:2',
        'valor_total'       => 'decimal:2',
        'nfse_emitida'      => 'boolean',
        'metadata'          => 'array',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
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
        return $this->belongsTo(Cliente::class);
    }

    public function itens()
    {
        return $this->hasMany(FaturaItem::class);
    }

    public function titulos()
    {
        return $this->hasMany(Titulo::class);
    }

    public function cobrancas()
    {
        return $this->hasMany(Cobranca::class, 'fatura_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeStatus($query, ?string $status)
    {
        if (!$status) {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeEmAberto($query)
    {
        return $query->whereIn('status', ['aberta', 'em_aberto', 'pendente']);
    }

    public function scopeDoPeriodo($query, ?string $inicio, ?string $fim)
    {
        if ($inicio) {
            $query->whereDate('data_emissao', '>=', $inicio);
        }

        if ($fim) {
            $query->whereDate('data_emissao', '<=', $fim);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getValorLiquidoAttribute()
    {
        $servicos   = $this->valor_servicos ?? 0;
        $descontos  = $this->valor_descontos ?? 0;
        $acrescimos = $this->valor_acrescimos ?? 0;
        $iss        = $this->valor_iss ?? 0;

        return (float) $servicos - (float) $descontos + (float) $acrescimos + (float) $iss;
    }

    public function getResumoAttribute(): string
    {
        $numero = $this->numero_fatura ?? $this->id;
        $cliente = optional($this->cliente)->nome_formatado ?? 'Cliente';

        $data = $this->data_emissao
            ? $this->data_emissao->format('d/m/Y')
            : '';

        return "Fatura {$numero} - {$cliente}" . ($data ? " ({$data})" : '');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function marcarComoEmitidaNfse(): void
    {
        $this->nfse_emitida = true;
        $this->save();
    }

    public function marcarComoNaoEmitidaNfse(): void
    {
        $this->nfse_emitida = false;
        $this->save();
    }

    /**
     * Gera um título padrão (1 parcela) com base no valor_total da fatura,
     * caso ainda não existam títulos vinculados.
     */
    public function gerarTituloPadrao(): ?Titulo
    {
        if ($this->titulos()->exists()) {
            return null; // já existem títulos, não gera de novo
        }

        $valor = $this->valor_total ?? $this->valor_servicos ?? 0;

        if ($valor <= 0) {
            return null;
        }

        return $this->titulos()->create([
            'cliente_id'      => $this->cliente_id,
            'numero_titulo'   => $this->numero_fatura ?? ('FT-' . $this->id),
            'nosso_numero'    => null,
            'data_emissao'    => $this->data_emissao ?? now()->toDateString(),
            'data_vencimento' => $this->data_vencimento ?? now()->toDateString(),
            'valor_original'  => $valor,
            'valor_juros'     => 0,
            'valor_multa'     => 0,
            'valor_desconto'  => 0,
            'valor_pago'      => 0,
            'valor_saldo'     => $valor,
            'status'          => 'aberto',
            'forma_pagamento' => null,
            'codigo_barras'   => null,
            'linha_digitavel' => null,
            'url_boleto'      => null,
            'observacoes'     => 'Título gerado automaticamente a partir da fatura.',
        ]);
    }
}
