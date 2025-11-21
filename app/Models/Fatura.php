<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fatura extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'faturas';
    protected $fillable = ['cliente_id', 'numero_fatura', 'data_emissao', 'data_vencimento', 'periodo_referencia', 'valor_servicos', 'valor_descontos', 'valor_acrescimos', 'valor_iss', 'valor_total', 'status', 'nfse_emitida', 'observacoes', 'metadata'];
    protected $casts = ['data_emissao' => 'date', 'data_vencimento' => 'date', 'valor_servicos' => 'decimal:2', 'valor_descontos' => 'decimal:2', 'valor_acrescimos' => 'decimal:2', 'valor_iss' => 'decimal:2', 'valor_total' => 'decimal:2', 'nfse_emitida' => 'boolean', 'metadata' => 'array'];
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function itens() { return $this->hasMany(FaturaItem::class); }
}
