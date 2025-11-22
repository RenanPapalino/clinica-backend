<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMensagem extends Model
{
    protected $table = 'chat_mensagens';

    protected $fillable = [
        'cliente_id',
        'canal',
        'origem',
        'identificador_externo',
        'mensagem',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }
}
s