<?php

namespace App\Models;

// IMPORTANTE: Esta linha é obrigatória
use App\Notifications\ResetAccountPasswordNotification;
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    // IMPORTANTE: Adicione o HasApiTokens dentro da classe
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'ativo',
    ];

    protected $attributes = [
        'role' => 'user',
        'ativo' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'ativo' => 'boolean',
    ];

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    public function isAtivo(): bool
    {
        return (bool) $this->ativo;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetAccountPasswordNotification($token));
    }
}
