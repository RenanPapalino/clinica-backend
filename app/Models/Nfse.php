<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nfse extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'nfse';

    protected $fillable = [
        'fatura_id',
        'cliente_id',
        'lote_id',

        'numero_nfse',
        'codigo_verificacao',
        'protocolo',

        'data_emissao',
        'data_envio',
        'data_autorizacao',

        'valor_servicos',
        'valor_deducoes',
        'valor_iss',
        'aliquota_iss',
        'valor_liquido',

        'status',               // emitida, cancelada, erro, pendente
        'codigo_servico',
        'discriminacao',

        'xml_nfse',
        'pdf_url',

        'mensagem_erro',
        'detalhes_erro',
    ];

    protected $casts = [
        'data_emissao'      => 'datetime',
        'data_envio'        => 'datetime',
        'data_autorizacao'  => 'datetime',

        'valor_servicos'    => 'decimal:2',
        'valor_deducoes'    => 'decimal:2',
        'valor_iss'         => 'decimal:2',
        'aliquota_iss'      => 'decimal:2',
        'valor_liquido'     => 'decimal:2',

        'detalhes_erro'     => 'array',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
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

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeEmitidas($query)
    {
        return $query->where('status', 'emitida');
    }

    public function scopePendentes($query)
    {
        return $query->where('status', 'pendente');
    }

    public function scopeComErro($query)
    {
        return $query->where('status', 'erro');
    }

    public function scopeDoCliente($query, $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    public function scopePeriodo($query, $inicio = null, $fim = null)
    {
        if ($inicio) {
            $query->whereDate('data_emissao', '>=', $inicio);
        }

        if ($fim) {
            $query->whereDate('data_emissao', '<=', $fim);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function getValorFormatadoAttribute()
    {
        return $this->valor_liquido
            ? 'R$ ' . number_format($this->valor_liquido, 2, ',', '.')
            : null;
    }

    public function getNumeroFormatadoAttribute()
    {
        if (!$this->numero_nfse) {
            return null;
        }

        return "NFSe nº {$this->numero_nfse}";
    }

    public function getResumoAttribute(): string
    {
        $numero = $this->numero_nfse ?? '-';
        $data = $this->data_emissao
            ? $this->data_emissao->format('d/m/Y')
            : 'sem data';

        return "NFSe {$numero} ({$data})";
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function marcarComoEmitida($protocolo = null)
    {
        $this->status = 'emitida';
        $this->data_autorizacao = now();
        if ($protocolo) {
            $this->protocolo = $protocolo;
        }
        $this->save();
    }

    public function marcarComoErro($mensagem, $detalhes = [])
    {
        $this->status = 'erro';
        $this->mensagem_erro = $mensagem;
        $this->detalhes_erro = $detalhes;
        $this->save();
    }

    public function marcarComoPendente()
    {
        $this->status = 'pendente';
        $this->save();
    }

    public function possuiErros(): bool
    {
        return $this->status === 'erro';
    }
}
