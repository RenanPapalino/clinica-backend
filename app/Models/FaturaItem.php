<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FaturaItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fatura_itens';

    protected $fillable = [
        'fatura_id',
        'servico_id',
        'item_numero',
        'descricao',
        'quantidade',
        'valor_unitario',
        'valor_total',
        'data_realizacao',
        'funcionario',
        'matricula',
    ];

    protected $casts = [
        'quantidade'      => 'integer',
        'valor_unitario'  => 'decimal:2',
        'valor_total'     => 'decimal:2',
        'data_realizacao' => 'date',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELACIONAMENTOS
    |--------------------------------------------------------------------------
    */

    public function fatura()
    {
        return $this->belongsTo(Fatura::class, 'fatura_id');
    }

    public function servico()
    {
        return $this->belongsTo(Servico::class, 'servico_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeDaFatura($query, $faturaId)
    {
        return $query->where('fatura_id', $faturaId);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getValorTotalCalculadoAttribute()
    {
        $qtd = $this->quantidade ?? 0;
        $unit = $this->valor_unitario ?? 0;

        return (float) $qtd * (float) $unit;
    }

    /*
    |--------------------------------------------------------------------------
    | MUTATORS / HELPERS
    |--------------------------------------------------------------------------
    */

    public function setValorUnitarioAttribute($value): void
    {
        if (is_string($value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['valor_unitario'] = $value !== '' ? (float) $value : null;
    }

    public function setValorTotalAttribute($value): void
    {
        if ($value === null || $value === '') {
            // se não vier nada, calcula com base em qtd * unit
            $this->attributes['valor_total'] = $this->valor_total_calculado;
            return;
        }

        if (is_string($value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['valor_total'] = (float) $value;
    }
}
