<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_retorna_role_e_ativo_do_usuario(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
            'ativo' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.ativo', true);
    }

    public function test_login_bloqueia_usuario_inativo(): void
    {
        $user = User::factory()->create([
            'email' => 'inativo@example.com',
            'ativo' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Usuário inativo.');
    }
}
