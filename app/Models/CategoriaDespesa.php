<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoriaDespesa extends Model
{
    use HasFactory;

    protected $table = 'categorias_despesa';

    protected $fillable = [
        'descricao',
        'codigo_contabil',
    ];
}
