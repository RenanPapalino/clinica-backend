<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servico extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'servicos';

    protected $fillable = [
        'codigo',                     // código interno do serviço
        'descricao',                  // descrição comercial
        'descricao_detalhada',        // opcional: texto longo
        'valor_unitario',             // valor padrão
        'cnae',                       // CNAE
        'codigo_servico_municipal',   // código usado na NFSe
        'aliquota_iss',               // %
        'tipo_servico',               // ex.: 'consulta', 'exame', 'laudo'
        'ativo',                      // boolean
        'observacoes',
    ];

    protected $casts = [
        'valor_unitario'   => 'decimal:2',
        'aliquota_iss'     => 'decimal:2',
        'ativo'            => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELACIONAMENTOS
    |--------------------------------------------------------------------------
    */

    // Se suas faturas tiverem referência ao serviço diretamente
    public function faturas()
    {
        return $this->hasMany(Fatura::class, 'servico_id');
    }

    // Se seus títulos (contas a receber) tiverem referência ao serviço
    public function titulos()
    {
        return $this->hasMany(Titulo::class, 'servico_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeBuscar($query, ?string $termo)
    {
        if (!$termo) {
            return $query;
        }

        $termo = trim($termo);

        return $query->where(function ($q) use ($termo) {
            $q->where('descricao', 'like', "%{$termo}%")
              ->orWhere('codigo', 'like', "%{$termo}%")
              ->orWhere('codigo_servico_municipal', 'like', "%{$termo}%")
              ->orWhere('cnae', 'like', "%{$termo}%");
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getDescricaoCompletaAttribute(): string
    {
        $base = $this->descricao ?? '';

        if (!empty($this->codigo)) {
            $base = "{$this->codigo} - {$base}";
        }

        if (!empty($this->valor_unitario)) {
            $valor = number_format($this->valor_unitario, 2, ',', '.');
            $base .= " (R$ {$valor})";
        }

        return $base;
    }

    /*
    |--------------------------------------------------------------------------
    | MUTATORS / HELPERS
    |--------------------------------------------------------------------------
    */

    public function setValorUnitarioAttribute($value): void
    {
        if (is_string($value)) {
            // aceita padrão "1.234,56" e converte
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['valor_unitario'] = $value !== '' ? (float) $value : null;
    }

    public function setAliquotaIssAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['aliquota_iss'] = null;
            return;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['aliquota_iss'] = (float) $value;
    }
}
