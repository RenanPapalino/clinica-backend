<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CentroCusto extends Model
{
    use HasFactory;

    protected $table = 'centros_custo';

    protected $fillable = ['nome', 'codigo', 'tipo', 'ativo'];
}