<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConfiguracaoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_usuario_com_role_e_ativo_pelas_configuracoes(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $response = $this->postJson('/api/configuracoes/usuarios', [
            'name' => 'Operador Financeiro',
            'email' => 'operador@example.com',
            'password' => 'secret123',
            'role' => 'viewer',
            'ativo' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.ativo', false);

        $this->assertDatabaseHas('users', [
            'email' => 'operador@example.com',
            'role' => 'viewer',
            'ativo' => 0,
        ]);
    }
}
