<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servico extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'servicos';
    protected $fillable = ['codigo', 'descricao', 'descricao_completa', 'valor_unitario', 'custo_unitario', 'codigo_servico_municipio', 'cnae', 'aliquota_iss', 'categoria', 'status', 'observacoes'];
    protected $casts = ['valor_unitario' => 'decimal:2', 'custo_unitario' => 'decimal:2', 'aliquota_iss' => 'decimal:2'];
}
