<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Titulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CobrancaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_cobranca_whatsapp_e_registra_uma_cobranca_por_titulo(): void
    {
        Sanctum::actingAs(User::factory()->create());

        putenv('N8N_COBRANCA_WHATSAPP_WEBHOOK=https://example.test/webhooks/cobranca');
        $_ENV['N8N_COBRANCA_WHATSAPP_WEBHOOK'] = 'https://example.test/webhooks/cobranca';
        $_SERVER['N8N_COBRANCA_WHATSAPP_WEBHOOK'] = 'https://example.test/webhooks/cobranca';

        Http::fake([
            'https://example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $cliente = Cliente::factory()->create([
            'telefone' => '11999999999',
        ]);

        $tituloA = Titulo::factory()->create([
            'cliente_id' => $cliente->id,
            'tipo' => 'receber',
            'status' => 'aberto',
            'data_vencimento' => now()->subDays(10)->toDateString(),
            'valor_original' => 120.00,
            'valor_saldo' => 120.00,
        ]);

        $tituloB = Titulo::factory()->create([
            'cliente_id' => $cliente->id,
            'tipo' => 'receber',
            'status' => 'aberto',
            'data_vencimento' => now()->subDays(5)->toDateString(),
            'valor_original' => 80.00,
            'valor_saldo' => 80.00,
        ]);

        $this->postJson("/api/cobrancas/enviar-whatsapp/{$cliente->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSentCount(1);

        $this->assertDatabaseHas('cobrancas', [
            'cliente_id' => $cliente->id,
            'titulo_id' => $tituloA->id,
            'meio' => 'whatsapp',
            'canal' => 'n8n',
            'status' => 'enviada',
            'valor_cobrado' => 120.00,
        ]);

        $this->assertDatabaseHas('cobrancas', [
            'cliente_id' => $cliente->id,
            'titulo_id' => $tituloB->id,
            'meio' => 'whatsapp',
            'canal' => 'n8n',
            'status' => 'enviada',
            'valor_cobrado' => 80.00,
        ]);
    }
}
