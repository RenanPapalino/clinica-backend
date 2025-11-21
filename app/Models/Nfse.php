<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nfse extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'nfse';
    protected $fillable = ['fatura_id', 'cliente_id', 'lote_id', 'numero_nfse', 'codigo_verificacao', 'protocolo', 'data_emissao', 'data_envio', 'data_autorizacao', 'valor_servicos', 'valor_deducoes', 'valor_iss', 'aliquota_iss', 'valor_liquido', 'status', 'codigo_servico', 'discriminacao', 'xml_nfse', 'pdf_url', 'mensagem_erro', 'detalhes_erro'];
    protected $casts = ['data_emissao' => 'datetime', 'data_envio' => 'datetime', 'data_autorizacao' => 'datetime', 'valor_servicos' => 'decimal:2', 'valor_deducoes' => 'decimal:2', 'valor_iss' => 'decimal:2', 'aliquota_iss' => 'decimal:2', 'valor_liquido' => 'decimal:2', 'detalhes_erro' => 'array'];
    public function fatura() { return $this->belongsTo(Fatura::class); }
    public function cliente() { return $this->belongsTo(Cliente::class); }
}
