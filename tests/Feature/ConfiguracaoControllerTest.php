<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SendGeneratedCredentialsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_cria_usuario_com_senha_gerada_em_tela_sem_enviar_email(): void
    {
        Notification::fake();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $response = $this->postJson('/api/configuracoes/usuarios', [
            'name' => 'Financeiro Sem Email',
            'email' => 'novo.usuario@example.com',
            'role' => 'user',
            'ativo' => true,
            'generate_password' => true,
            'send_credentials_email' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', false)
            ->assertJsonPath('credentials.login', 'novo.usuario@example.com')
            ->assertJsonPath('credentials.password_generated', true)
            ->assertJsonPath('credentials.sent_by_email', false);

        $this->assertIsString($response->json('credentials.password'));
        $this->assertNotEmpty($response->json('credentials.password'));

        Notification::assertNothingSent();
    }

    public function test_cria_usuario_e_envia_credenciais_por_email(): void
    {
        Notification::fake();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $response = $this->postJson('/api/configuracoes/usuarios', [
            'name' => 'Financeiro Com Email',
            'email' => 'email.usuario@example.com',
            'role' => 'user',
            'ativo' => true,
            'generate_password' => true,
            'send_credentials_email' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', true)
            ->assertJsonPath('credentials.login', 'email.usuario@example.com')
            ->assertJsonPath('credentials.password_generated', true)
            ->assertJsonPath('credentials.sent_by_email', true);

        $usuario = User::where('email', 'email.usuario@example.com')->firstOrFail();

        Notification::assertSentTo($usuario, SendGeneratedCredentialsNotification::class);
    }
}
