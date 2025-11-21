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
        'regime_tributario',
        'aliquota_iss',
        'prazo_pagamento_dias',
        'observacoes',
        'status',
    ];

    protected $casts = [
        'aliquota_iss' => 'decimal:2',
        'prazo_pagamento_dias' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($cliente) {
            if ($cliente->cnpj) {
                $cliente->cnpj = self::formatarCNPJ($cliente->cnpj);
            }
        });
    }

    // Scopes
    public function scopeAtivos($query)
    {
        return $query->where('status', 'ativo');
    }

    public function scopeInativos($query)
    {
        return $query->where('status', 'inativo');
    }

    public function scopeBuscar($query, $termo)
    {
        return $query->where(function($q) use ($termo) {
            $q->where('razao_social', 'like', "%{$termo}%")
              ->orWhere('nome_fantasia', 'like', "%{$termo}%")
              ->orWhere('cnpj', 'like', "%{$termo}%");
        });
    }

    // Accessors
    public function getCnpjFormatadoAttribute()
    {
        return self::formatarCNPJ($this->cnpj);
    }

    public function getEnderecoCompletoAttribute()
    {
        $partes = array_filter([
            $this->logradouro,
            $this->numero,
            $this->complemento,
            $this->bairro,
            $this->cidade,
            $this->uf,
        ]);

        return implode(', ', $partes);
    }

    public function getAtivoAttribute()
    {
        return $this->status === 'ativo';
    }

    // Helpers estáticos
    public static function formatarCNPJ($cnpj)
    {
        if (!$cnpj) {
            return null;
        }

        // Remove tudo que não é número
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);

        // Formata: 00.000.000/0000-00
        if (strlen($cnpj) === 14) {
            return sprintf(
                '%s.%s.%s/%s-%s',
                substr($cnpj, 0, 2),
                substr($cnpj, 2, 3),
                substr($cnpj, 5, 3),
                substr($cnpj, 8, 4),
                substr($cnpj, 12, 2)
            );
        }

        return $cnpj;
    }

    public static function validarCNPJ($cnpj)
    {
        // Remove caracteres especiais
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);

        // Verifica se tem 14 dígitos
        if (strlen($cnpj) != 14) {
            return false;
        }

        // Verifica se todos os dígitos são iguais
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }

        // Validação dos dígitos verificadores
        for ($t = 12; $t < 14; $t++) {
            $d = 0;
            $c = 0;
            for ($m = $t - 7; $m >= 2; $m--, $c++) {
                $d += $cnpj[$c] * $m;
            }
            for ($m = 9; $m >= 2 && $c < $t; $m--, $c++) {
                $d += $cnpj[$c] * $m;
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cnpj[$c] != $d) {
                return false;
            }
        }

        return true;
    }
}
