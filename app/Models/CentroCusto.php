<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CentroCusto extends Model
{
    use HasFactory;

    protected $table = 'centros_custo';

    // O CAMPO AQUI PRECISA SER 'descricao'. SE ESTIVER 'nome', VAI DAR ERRO!
    protected $fillable = [
        'codigo', 
        'descricao', 
        'ativo'
    ];
    
    protected $casts = [
        'ativo' => 'boolean'
    ];
}