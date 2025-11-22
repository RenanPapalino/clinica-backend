<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Configuracao extends Model
{
    use HasFactory;

    protected $table = 'configuracoes';

    protected $fillable = [
        'chave',
        'valor',
        'descricao'
    ];

    protected $casts = [
        'valor' => 'string' // JSON será armazenado como string
    ];

    /**
     * Busca uma configuração por chave
     */
    public static function get($chave, $default = null)
    {
        $config = self::where('chave', $chave)->first();
        
        if (!$config) {
            return $default;
        }

        return json_decode($config->valor, true);
    }

    /**
     * Define uma configuração
     */
    public static function set($chave, $valor, $descricao = null)
    {
        return self::updateOrCreate(
            ['chave' => $chave],
            [
                'valor' => is_array($valor) ? json_encode($valor) : $valor,
                'descricao' => $descricao
            ]
        );
    }
}
