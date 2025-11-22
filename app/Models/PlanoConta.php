<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlanoConta extends Model
{
    use HasFactory;

    protected $table = 'planos_contas';

    protected $fillable = [
        'codigo', 'descricao', 'tipo', 'natureza', 
        'conta_contabil', 'analitica', 'inibir_relatorios', 
        'conta_pai_id', 'ativo'
    ];

    // Auto-relacionamento para hierarquia (Pai -> Filhos)
    public function filhos()
    {
        return $this->hasMany(PlanoConta::class, 'conta_pai_id');
    }

    public function pai()
    {
        return $this->belongsTo(PlanoConta::class, 'conta_pai_id');
    }
}