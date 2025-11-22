<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'cnpj',
        'razao_social',
        'nome_fantasia',
        'inscricao_municipal',
        'inscricao_estadual',
        'email',
        'telefone',
        'celular',
        'site',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'status',                 // 'ativo' | 'inativo'
        'aliquota_iss',
        'prazo_pagamento_dias',
        'observacoes',
    ];

    protected $casts = [
        'aliquota_iss'          => 'decimal:2',
        'prazo_pagamento_dias'  => 'integer',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
        'deleted_at'            => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Boot – normaliza CNPJ e email antes de salvar
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (Cliente $model) {
            if (!empty($model->cnpj)) {
                $model->cnpj = preg_replace('/\D/', '', $model->cnpj);
            }

            if (!empty($model->email)) {
                $model->email = mb_strtolower(trim($model->email));
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONAMENTOS
    |--------------------------------------------------------------------------
    */

    public function faturas()
    {
        return $this->hasMany(Fatura::class, 'cliente_id');
    }

    public function titulos()
    {
        return $this->hasMany(Titulo::class, 'cliente_id');
    }

    public function cobrancas()
    {
        return $this->hasMany(Cobranca::class, 'cliente_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeAtivos($query)
    {
        return $query->where('status', 'ativo');
    }

    public function scopeInativos($query)
    {
        return $query->where('status', 'inativo');
    }

    public function scopeBuscar($query, ?string $termo)
    {
        if (!$termo) {
            return $query;
        }

        $termo = trim($termo);

        return $query->where(function ($q) use ($termo) {
            $q->where('razao_social', 'like', "%{$termo}%")
                ->orWhere('nome_fantasia', 'like', "%{$termo}%")
                ->orWhere('cnpj', 'like', '%' . preg_replace('/\D/', '', $termo) . '%');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getNomeFormatadoAttribute(): string
    {
        return $this->nome_fantasia ?: ($this->razao_social ?? '');
    }

    public function getEnderecoCompletoAttribute(): string
    {
        $partes = array_filter([
            $this->logradouro,
            $this->numero,
            $this->complemento,
            $this->bairro,
            $this->cidade,
            $this->uf,
            $this->cep,
        ]);

        return implode(', ', $partes);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS / VALIDAÇÃO DE CNPJ
    |--------------------------------------------------------------------------
    */

    public function setCnpjAttribute($value): void
    {
        $this->attributes['cnpj'] = $value
            ? preg_replace('/\D/', '', $value)
            : null;
    }

    public function getCnpjFormatadoAttribute(): ?string
    {
        if (!$this->cnpj || strlen($this->cnpj) !== 14) {
            return $this->cnpj;
        }

        $c = $this->cnpj;
        return substr($c, 0, 2) . '.' .
               substr($c, 2, 3) . '.' .
               substr($c, 5, 3) . '/' .
               substr($c, 8, 4) . '-' .
               substr($c, 12, 2);
    }

    public static function isValidCnpj(?string $cnpj): bool
    {
        if (!$cnpj) {
            return false;
        }

        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) != 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $t = 12;
        for ($i = 0; $i < 2; $i++) {
            $d = 0;
            $c = 0;
            for ($m = $t - 7; $m >= 2; $m--, $c++) {
                $d += $cnpj[$c] * $m;
            }
            for ($m = 9; $m >= 2 && $c < $t; $m--, $c++) {
                $d += $cnpj[$c] * $m;
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int)$cnpj[$c] !== $d) {
                return false;
            }
            $t++;
        }

        return true;
    }
}
