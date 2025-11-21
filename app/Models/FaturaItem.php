<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaturaItem extends Model
{
    use HasFactory;
    protected $table = 'fatura_itens';
    protected $fillable = ['fatura_id', 'servico_id', 'item_numero', 'descricao', 'quantidade', 'valor_unitario', 'valor_total', 'data_realizacao', 'funcionario', 'matricula'];
    protected $casts = ['quantidade' => 'integer', 'valor_unitario' => 'decimal:2', 'valor_total' => 'decimal:2', 'data_realizacao' => 'date'];
    public function fatura() { return $this->belongsTo(Fatura::class); }
    public function servico() { return $this->belongsTo(Servico::class); }
}
