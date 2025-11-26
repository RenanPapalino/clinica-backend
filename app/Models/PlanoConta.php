<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlanoConta extends Model
{
    use HasFactory;

    protected $table = 'planos_contas';

    // GARANTA QUE TODOS ESTES CAMPOS ESTEJAM AQUI
    protected $fillable = [
        'codigo', 
        'descricao', 
        'tipo', 
        'natureza', 
        'conta_contabil', 
        'analitica', 
        'conta_pai_id', 
        'ativo'
    ];

    protected $casts = [
        'analitica' => 'boolean',
        'ativo' => 'boolean'
    ];

    public function filhos()
    {
        return $this->hasMany(PlanoConta::class, 'conta_pai_id');
    }

    public function pai()
    {
        return $this->belongsTo(PlanoConta::class, 'conta_pai_id');
    }
}