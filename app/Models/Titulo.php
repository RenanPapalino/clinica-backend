<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Titulo extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'titulos';
    protected $fillable = ['cliente_id', 'fatura_id', 'numero_titulo', 'nosso_numero', 'data_emissao', 'data_vencimento', 'data_pagamento', 'valor_original', 'valor_juros', 'valor_multa', 'valor_desconto', 'valor_pago', 'valor_saldo', 'status', 'forma_pagamento', 'codigo_barras', 'linha_digitavel', 'url_boleto', 'observacoes'];
    protected $casts = ['data_emissao' => 'date', 'data_vencimento' => 'date', 'data_pagamento' => 'date', 'valor_original' => 'decimal:2', 'valor_juros' => 'decimal:2', 'valor_multa' => 'decimal:2', 'valor_desconto' => 'decimal:2', 'valor_pago' => 'decimal:2', 'valor_saldo' => 'decimal:2'];
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function fatura() { return $this->belongsTo(Fatura::class); }
}
