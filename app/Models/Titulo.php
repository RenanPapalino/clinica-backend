<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Titulo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'titulos';

    protected $fillable = [
        'cliente_id',
        'fatura_id',

        'numero_titulo',
        'nosso_numero',

        'data_emissao',
        'data_vencimento',
        'data_pagamento',

        'valor_original',
        'valor_juros',
        'valor_multa',
        'valor_desconto',
        'valor_pago',
        'valor_saldo',

        'status',           // aberto, pendente, vencido, pago, cancelado
        'forma_pagamento',  // pix, boleto, dinheiro, ted, cartão...

        'codigo_barras',
        'linha_digitavel',
        'url_boleto',

        'observacoes',
    ];

    protected $casts = [
        'data_emissao'    => 'date',
        'data_vencimento' => 'date',
        'data_pagamento'  => 'date',

        'valor_original'  => 'decimal:2',
        'valor_juros'     => 'decimal:2',
        'valor_multa'     => 'decimal:2',
        'valor_desconto'  => 'decimal:2',
        'valor_pago'      => 'decimal:2',
        'valor_saldo'     => 'decimal:2',

        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    protected $hidden = ['deleted_at'];

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

    public function cobrancas()
    {
        return $this->hasMany(Cobranca::class, 'titulo_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeAberto($query)
    {
        return $query->whereIn('status', ['aberto', 'pendente']);
    }

    public function scopeVencidos($query)
    {
        return $query->where('data_vencimento', '<', Carbon::today())
                     ->where('status', '!=', 'pago');
    }

    public function scopeHoje($query)
    {
        return $query->whereDate('data_vencimento', Carbon::today());
    }

    public function scopeAmanha($query)
    {
        return $query->whereDate('data_vencimento', Carbon::tomorrow());
    }

    public function scopeAtrasoMaiorQue($query, int $dias)
    {
        return $query->where('data_vencimento', '<=', Carbon::today()->subDays($dias))
                     ->where('status', '!=', 'pago');
    }

    public function scopeDoCliente($query, $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getValorFormatadoAttribute()
    {
        return 'R$ ' . number_format($this->valor_original ?? 0, 2, ',', '.');
    }

    public function getStatusFormatadoAttribute()
    {
        return ucfirst($this->status ?? 'desconhecido');
    }

    public function getResumoAttribute()
    {
        $numero = $this->numero_titulo ?? $this->id;
        $data = $this->data_vencimento?->format('d/m/Y');

        return "Título {$numero} (Venc. {$data})";
    }

    public function getDiasAtrasoAttribute(): int
    {
        if (!$this->data_vencimento || $this->status === 'pago') {
            return 0;
        }

        return Carbon::today()->diffInDays($this->data_vencimento, false) * -1;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function estaPago(): bool
    {
        return $this->status === 'pago' || $this->valor_saldo <= 0;
    }

    public function estaVencido(): bool
    {
        return !$this->estaPago() &&
               $this->data_vencimento &&
               $this->data_vencimento->isBefore(Carbon::today());
    }

    public function diasAtraso(): int
    {
        return $this->dias_atraso;
    }

    public function recalcularSaldo(): void
    {
        $base = (float) $this->valor_original;

        $descontos = (float) $this->valor_desconto;
        $juros     = (float) $this->valor_juros;
        $multa     = (float) $this->valor_multa;

        $pago      = (float) $this->valor_pago;

        $saldo = ($base + $juros + $multa - $descontos) - $pago;

        $this->valor_saldo = max($saldo, 0);
        $this->save();
    }

    public function registrarPagamento(float $valor, ?string $forma = null): void
    {
        $this->valor_pago = ((float) $this->valor_pago + $valor);
        $this->forma_pagamento = $forma ?? $this->forma_pagamento;
        $this->data_pagamento = now();

        $this->recalcularSaldo();

        if ($this->valor_saldo <= 0) {
            $this->status = 'pago';
        }

        $this->save();
    }

    public function marcarComoVencido(): void
    {
        if (!$this->estaPago() && $this->data_vencimento < Carbon::today()) {
            $this->status = 'vencido';
            $this->save();
        }
    }

    // Relacionamento com Rateios
    public function rateios()
    {
        return $this->hasMany(TituloRateio::class);
    }

    // Relacionamento com Fornecedor (Novo)
    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class);
    }

    // Método auxiliar para verificar se o total rateado bate com o valor do título
    public function validarRateio(): bool
    {
        $totalRateado = $this->rateios()->sum('valor');
        // Aceita diferença de 1 centavo
        return abs($totalRateado - $this->valor_original) <= 0.01;
    }

    // Relacionamento com Plano de Contas Principal (Cabeçalho)
    public function planoConta()
    {
        return $this->belongsTo(PlanoConta::class);
    }

    // Relacionamento com Centro de Custo Principal (Cabeçalho)
    public function centroCusto()
    {
        return $this->belongsTo(CentroCusto::class);
    }
}
