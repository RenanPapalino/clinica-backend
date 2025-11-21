<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cobranca extends Model
{
    use HasFactory;

    protected $table = 'cobrancas';

    protected $fillable = [
        'fatura_id',
        'data_envio',
        'canal', // email, whatsapp, sms
        'destinatario',
        'status', // enviada, erro, pendente
        'tentativas',
        'mensagem_erro',
    ];

    protected $casts = [
        'data_envio' => 'datetime',
    ];

    /**
     * Relacionamento com Fatura
     */
    public function fatura()
    {
        return $this->belongsTo(Fatura::class);
    }
}
