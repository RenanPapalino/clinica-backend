<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmacao_de_acao_langchain_e_delegada_ao_runtime(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'https://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://langchain-runtime.test/chat/resume' => Http::response([
                'success' => true,
                'message' => 'Acao concluida com sucesso: 1 registro(s) criado(s).',
                'detalhes' => [
                    'resumo' => ['criados' => 1, 'erros' => 0],
                    'registros' => [
                        ['id' => 55, 'razao_social' => 'CLINICA NOVA LTDA'],
                    ],
                    'erros_lista' => [],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chat/confirmar', [
            'acao' => 'criar_cliente',
            'dados' => [
                [
                    'cnpj' => '12345678000190',
                    'razao_social' => 'CLINICA NOVA LTDA',
                ],
            ],
            'metadata' => [
                'fonte' => 'langchain-runtime',
                'runtime_pending_action_id' => 'pending-123',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('detalhes.resumo.criados', 1);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://langchain-runtime.test/chat/resume'
                && $request->hasHeader('X-Agent-Secret', 'runtime-secret')
                && $request['acao'] === 'criar_cliente'
                && $request['metadata']['runtime_pending_action_id'] === 'pending-123';
        });
    }

    public function test_upload_do_chat_pode_espelhar_arquivo_no_google_drive_para_ingestao_assincrona(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.service_account_json' => $this->googleDriveServiceAccountJson(),
            'services.google_drive.timeout' => 10,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'id' => 'drive-file-001',
                'name' => 'chat-upload_u1_sessao_arquivo_clientes.xlsx',
            ], 200),
            'https://www.googleapis.com/upload/drive/v3/files/drive-file-001*' => Http::response([
                'id' => 'drive-file-001',
                'name' => 'chat-upload_u1_sessao_arquivo_clientes.xlsx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'parents' => ['folder-drive-123'],
                'webViewLink' => 'https://drive.google.com/file/d/drive-file-001/view',
                'createdTime' => now()->toISOString(),
                'modifiedTime' => now()->toISOString(),
            ], 200),
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Arquivo recebido e pronto para analise.',
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => 'Processe a planilha em anexo.',
            'tipo_processamento' => 'financeiro',
            'espelhar_no_drive' => '1',
            'drive_required' => '1',
            'arquivo' => UploadedFile::fake()->create(
                'clientes.xlsx',
                16,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Arquivo recebido e pronto para analise.')
            ->assertJsonPath('arquivo_ingestao.success', true)
            ->assertJsonPath('arquivo_ingestao.provider', 'google_drive')
            ->assertJsonPath('arquivo_ingestao.file_id', 'drive-file-001')
            ->assertJsonPath('arquivo_ingestao.folder_id', 'folder-drive-123');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files/drive-file-001'));
        Http::assertSent(fn ($request) => $request->url() === 'http://langchain-runtime.test/chat/file');
    }

    private function googleDriveServiceAccountJson(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'medintelligence-tests',
            'private_key_id' => 'test-key-id',
            'private_key' => $privateKey,
            'client_email' => 'chat-upload-test@medintelligence-tests.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_UNESCAPED_SLASHES);
    }
}
