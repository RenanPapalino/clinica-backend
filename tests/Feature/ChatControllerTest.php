<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            'chatbot.chat_upload.index_to_rag' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => null,
            'services.google_drive.oauth_client_id' => null,
            'services.google_drive.oauth_client_secret' => null,
            'services.google_drive.oauth_refresh_token' => null,
            'services.google_drive.service_account_json' => $this->googleDriveServiceAccountJson(),
            'services.google_drive.timeout' => 10,
        ]);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        Sanctum::actingAs($user);

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
            'arquivo' => UploadedFile::fake()->createWithContent(
                'clientes.csv',
                "razao_social,cnpj\nClinica Exemplo,12345678000190\nClinica Dois,98765432000100\n"
            ),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Arquivo recebido e pronto para analise.')
            ->assertJsonPath('arquivo_ingestao.success', true)
            ->assertJsonPath('arquivo_ingestao.auth_mode', 'service_account')
            ->assertJsonPath('arquivo_ingestao.provider', 'google_drive')
            ->assertJsonPath('arquivo_ingestao.file_id', 'drive-file-001')
            ->assertJsonPath('arquivo_ingestao.folder_id', 'folder-drive-123')
            ->assertJsonPath('rag_ingestao.success', true)
            ->assertJsonPath('rag_ingestao.provider', 'rag_mysql')
            ->assertJsonPath('rag_ingestao.business_context', 'chat_upload')
            ->assertJsonPath('rag_ingestao.context_key', 'chat_user_' . $user->id);

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files/drive-file-001'));
        Http::assertSent(fn ($request) => $request->url() === 'http://langchain-runtime.test/chat/file');

        $document = RagDocument::firstOrFail();
        $this->assertSame('chatbot_upload', $document->source_system);
        $this->assertSame('chat-upload:drive-file-001', $document->external_id);
        $this->assertSame('chat_upload', $document->business_context);
        $this->assertSame('chat_user_' . $user->id, $document->context_key);
        $this->assertDatabaseCount('rag_chunks', 1);
        $this->assertStringContainsString('Clinica Exemplo', RagChunk::firstOrFail()->content);
    }

    public function test_upload_do_chat_com_imagem_indexa_conhecimento_com_base_na_analise_visual(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => false,
            'chatbot.chat_upload.index_to_rag' => true,
            'services.openai.key' => 'openai-test-key',
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'resumo' => 'Imagem com tabela de clientes.',
                            'texto_extraido' => 'Alpha Sistemas Ltda CNPJ 11222333000132',
                            'itens_relevantes' => ['Cliente Alpha Sistemas Ltda', 'CNPJ 11222333000132'],
                            'proxima_acao_sugerida' => 'sincronizar_clientes',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ], 200),
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Imagem recebida para analise.',
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => 'Use a imagem como base de conhecimento.',
            'tipo_processamento' => 'cadastros',
            'arquivo' => UploadedFile::fake()->image('clientes.png'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('rag_ingestao.success', true)
            ->assertJsonPath('rag_ingestao.extraction_mode', 'image');

        $this->assertDatabaseHas('rag_documents', [
            'business_context' => 'chat_upload',
        ]);
        $this->assertStringContainsString('Alpha Sistemas Ltda', RagChunk::firstOrFail()->content);
    }

    public function test_upload_do_chat_pode_espelhar_arquivo_no_google_drive_via_oauth_refresh_token(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => null,
            'services.google_drive.oauth_client_id' => 'oauth-client-id',
            'services.google_drive.oauth_client_secret' => 'oauth-client-secret',
            'services.google_drive.oauth_refresh_token' => 'oauth-refresh-token',
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
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
            ->assertJsonPath('arquivo_ingestao.auth_mode', 'oauth_refresh_token')
            ->assertJsonPath('arquivo_ingestao.provider', 'google_drive')
            ->assertJsonPath('arquivo_ingestao.file_id', 'drive-file-001')
            ->assertJsonPath('arquivo_ingestao.folder_id', 'folder-drive-123');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['client_id'] === 'oauth-client-id'
            && $request['client_secret'] === 'oauth-client-secret'
            && $request['refresh_token'] === 'oauth-refresh-token');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files/drive-file-001'));
        Http::assertSent(fn ($request) => $request->url() === 'http://langchain-runtime.test/chat/file');
    }

    public function test_upload_do_chat_pode_espelhar_arquivo_no_google_drive_via_access_token_direto(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => 'oauth-access-token',
            'services.google_drive.oauth_client_id' => null,
            'services.google_drive.oauth_client_secret' => null,
            'services.google_drive.oauth_refresh_token' => null,
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
            'services.google_drive.timeout' => 10,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
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
            ->assertJsonPath('arquivo_ingestao.auth_mode', 'oauth_access_token')
            ->assertJsonPath('arquivo_ingestao.provider', 'google_drive')
            ->assertJsonPath('arquivo_ingestao.file_id', 'drive-file-001')
            ->assertJsonPath('arquivo_ingestao.folder_id', 'folder-drive-123');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files/drive-file-001'));
        Http::assertSent(fn ($request) => $request->url() === 'http://langchain-runtime.test/chat/file');
    }

    public function test_upload_do_chat_prefere_refresh_token_quando_refresh_e_access_token_estao_configurados(): void
    {
        Cache::flush();

        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => 'oauth-access-token-antigo',
            'services.google_drive.oauth_client_id' => 'oauth-client-id',
            'services.google_drive.oauth_client_secret' => 'oauth-client-secret',
            'services.google_drive.oauth_refresh_token' => 'oauth-refresh-token',
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
            'services.google_drive.timeout' => 10,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token-novo',
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
            ->assertJsonPath('arquivo_ingestao.success', true)
            ->assertJsonPath('arquivo_ingestao.auth_mode', 'oauth_refresh_token');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['client_id'] === 'oauth-client-id'
            && $request['client_secret'] === 'oauth-client-secret'
            && $request['refresh_token'] === 'oauth-refresh-token');
    }

    public function test_upload_do_chat_reutiliza_access_token_cacheado_gerado_por_refresh_token(): void
    {
        Cache::flush();

        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => null,
            'services.google_drive.oauth_client_id' => 'oauth-client-id',
            'services.google_drive.oauth_client_secret' => 'oauth-client-secret',
            'services.google_drive.oauth_refresh_token' => 'oauth-refresh-token',
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
            'services.google_drive.timeout' => 10,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token-cacheado',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push([
                    'id' => 'drive-file-001',
                    'name' => 'primeiro.xlsx',
                ], 200)
                ->push([
                    'id' => 'drive-file-002',
                    'name' => 'segundo.xlsx',
                ], 200),
            'https://www.googleapis.com/upload/drive/v3/files/drive-file-001*' => Http::response([
                'id' => 'drive-file-001',
                'name' => 'primeiro.xlsx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'parents' => ['folder-drive-123'],
            ], 200),
            'https://www.googleapis.com/upload/drive/v3/files/drive-file-002*' => Http::response([
                'id' => 'drive-file-002',
                'name' => 'segundo.xlsx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'parents' => ['folder-drive-123'],
            ], 200),
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Arquivo recebido e pronto para analise.',
            ], 200),
        ]);

        $payload = [
            'mensagem' => 'Processe a planilha em anexo.',
            'tipo_processamento' => 'financeiro',
            'espelhar_no_drive' => '1',
            'drive_required' => '1',
        ];

        $this->post('/api/chat/enviar', $payload + [
            'arquivo' => UploadedFile::fake()->create('clientes-1.xlsx', 16, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/chat/enviar', $payload + [
            'arquivo' => UploadedFile::fake()->create('clientes-2.xlsx', 16, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ], ['Accept' => 'application/json'])->assertOk();

        $tokenRequests = collect(Http::recorded())->filter(
            fn (array $call) => $call[0]->url() === 'https://oauth2.googleapis.com/token'
        );

        $this->assertCount(1, $tokenRequests);
    }

    public function test_upload_do_chat_renova_access_token_cacheado_quando_google_retorna_401(): void
    {
        Cache::flush();

        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => true,
            'chatbot.chat_upload.mirror_to_drive_required' => true,
            'chatbot.chat_upload.drive_name_prefix' => 'chat-upload',
            'services.google_drive.folder_id' => 'folder-drive-123',
            'services.google_drive.oauth_access_token' => null,
            'services.google_drive.oauth_client_id' => 'oauth-client-id',
            'services.google_drive.oauth_client_secret' => 'oauth-client-secret',
            'services.google_drive.oauth_refresh_token' => 'oauth-refresh-token',
            'services.google_drive.service_account_json' => null,
            'services.google_drive.service_account_path' => null,
            'services.google_drive.timeout' => 10,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push([
                    'access_token' => 'google-access-token-expirado',
                    'expires_in' => 3600,
                ], 200)
                ->push([
                    'access_token' => 'google-access-token-renovado',
                    'expires_in' => 3600,
                ], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push(['error' => ['message' => 'Unauthorized']], 401)
                ->push([
                    'id' => 'drive-file-001',
                    'name' => 'clientes.xlsx',
                ], 200),
            'https://www.googleapis.com/upload/drive/v3/files/drive-file-001*' => Http::response([
                'id' => 'drive-file-001',
                'name' => 'clientes.xlsx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'parents' => ['folder-drive-123'],
            ], 200),
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Arquivo recebido e pronto para analise.',
            ], 200),
        ]);

        $this->post('/api/chat/enviar', [
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
        ])->assertOk()
            ->assertJsonPath('arquivo_ingestao.success', true)
            ->assertJsonPath('arquivo_ingestao.auth_mode', 'oauth_refresh_token');

        $tokenRequests = collect(Http::recorded())->filter(
            fn (array $call) => $call[0]->url() === 'https://oauth2.googleapis.com/token'
        );
        $createRequests = collect(Http::recorded())->filter(
            fn (array $call) => str_starts_with($call[0]->url(), 'https://www.googleapis.com/drive/v3/files')
        );

        $this->assertCount(2, $tokenRequests);
        $this->assertCount(2, $createRequests);
    }

    public function test_upload_com_anexo_nao_usa_resposta_local_rapida(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => false,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Analisei o arquivo e comecei a estruturar os dados para importacao.',
                'acao_sugerida' => 'criar_cliente',
                'dados_estruturados' => [
                    'tipo' => 'cliente',
                    'acao_sugerida' => 'criar_cliente',
                    'dados_mapeados' => [
                        [
                            'razao_social' => 'CLINICA TESTE LTDA',
                        ],
                    ],
                    'metadata' => [
                        'runtime_requires_more_info' => true,
                        'runtime_pending_fields' => ['cnpj'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => 'Quantos clientes temos? Use o arquivo para atualizar a base.',
            'tipo_processamento' => 'cadastros',
            'arquivo' => UploadedFile::fake()->create(
                'clientes.csv',
                8,
                'text/csv'
            ),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Analisei o arquivo e comecei a estruturar os dados para importacao.')
            ->assertJsonPath('acao_sugerida', 'criar_cliente')
            ->assertJsonPath('dados_estruturados.metadata.runtime_requires_more_info', true)
            ->assertJsonPath('dados_estruturados.metadata.runtime_pending_fields.0', 'cnpj');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://langchain-runtime.test/chat/file'
                && str_contains($request->body(), '"base64"');
        });
    }

    public function test_upload_de_imagem_no_chat_e_encaminhado_ao_runtime(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => false,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Imagem recebida e encaminhada para analise.',
                'acao_sugerida' => null,
                'dados_estruturados' => null,
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => 'Analise a imagem anexada.',
            'tipo_processamento' => 'financeiro',
            'arquivo' => UploadedFile::fake()->image('documento.png'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Imagem recebida e encaminhada para analise.');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://langchain-runtime.test/chat/file'
                && str_contains($request->body(), '"base64"');
        });
    }

    public function test_upload_de_audio_no_chat_e_encaminhado_ao_runtime(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => false,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Audio recebido e encaminhado para transcricao.',
                'acao_sugerida' => null,
                'dados_estruturados' => null,
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => 'Transcreva o audio e processe o pedido.',
            'tipo_processamento' => 'financeiro',
            'arquivo' => UploadedFile::fake()->create('pedido.mp3', 128, 'audio/mpeg'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Audio recebido e encaminhado para transcricao.');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://langchain-runtime.test/chat/file'
                && str_contains($request->body(), '"base64"');
        });
    }

    public function test_gravacao_de_audio_webm_sem_mensagem_e_normalizada_antes_do_runtime(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
            'chatbot.chat_upload.mirror_to_drive' => false,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'ativo' => true,
        ]));

        Http::fake([
            'http://langchain-runtime.test/chat/file' => Http::response([
                'mensagem' => 'Gravacao recebida e encaminhada para transcricao.',
                'acao_sugerida' => null,
                'dados_estruturados' => null,
            ], 200),
        ]);

        $response = $this->post('/api/chat/enviar', [
            'mensagem' => '',
            'tipo_processamento' => 'financeiro',
            'arquivo_mime_type' => 'audio/webm',
            'arquivo_nome' => 'gravacao.webm',
            'arquivo' => UploadedFile::fake()->create('blob', 128, 'audio/webm'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('content', 'Gravacao recebida e encaminhada para transcricao.');

        Http::assertSentCount(1);

        $mensagemUsuario = ChatMessage::query()
            ->where('role', 'user')
            ->latest('id')
            ->first();

        $this->assertNotNull($mensagemUsuario);
        $this->assertSame('audio/webm', $mensagemUsuario->metadata['file_mime_type'] ?? null);
        $this->assertSame('audio', $mensagemUsuario->metadata['file_kind'] ?? null);
        $this->assertSame('gravacao.webm', $mensagemUsuario->metadata['file_name'] ?? null);
    }

    public function test_confirmacao_de_gerar_fatura_langchain_e_delegada_ao_runtime(): void
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
                        ['id' => 77, 'numero_fatura' => 'FAT-202603-0001'],
                    ],
                    'erros_lista' => [],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chat/confirmar', [
            'acao' => 'gerar_fatura',
            'dados' => [
                [
                    'cliente_id' => 10,
                    'periodo_referencia' => '2026-03',
                    'data_vencimento' => '2026-03-31',
                    'itens' => [
                        [
                            'descricao' => 'Exame ocupacional',
                            'quantidade' => 2,
                            'valor_unitario' => 150.00,
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'fonte' => 'langchain-runtime',
                'runtime_pending_action_id' => 'pending-fat-001',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('detalhes.resumo.criados', 1);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://langchain-runtime.test/chat/resume'
                && $request->hasHeader('X-Agent-Secret', 'runtime-secret')
                && $request['acao'] === 'gerar_fatura'
                && $request['metadata']['runtime_pending_action_id'] === 'pending-fat-001';
        });
    }

    public function test_confirmacao_de_gerar_boleto_langchain_e_delegada_ao_runtime(): void
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
                'message' => 'Gerei o boleto da fatura FAT-202603-5937 com sucesso.',
                'detalhes' => [
                    'resumo' => ['criados' => 1, 'erros' => 0],
                    'registros' => [
                        [
                            'fatura' => ['id' => 900, 'numero_fatura' => 'FAT-202603-5937'],
                            'boleto' => ['titulo_id' => 700, 'nosso_numero' => 'LOCAL0000000700'],
                        ],
                    ],
                    'erros_lista' => [],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chat/confirmar', [
            'acao' => 'gerar_boleto',
            'dados' => [
                [
                    'fatura_id' => 900,
                    'fatura_label' => 'FAT-202603-5937',
                ],
            ],
            'metadata' => [
                'fonte' => 'langchain-runtime',
                'runtime_pending_action_id' => 'pending-bol-001',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('detalhes.resumo.criados', 1);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://langchain-runtime.test/chat/resume'
                && $request->hasHeader('X-Agent-Secret', 'runtime-secret')
                && $request['acao'] === 'gerar_boleto'
                && $request['metadata']['runtime_pending_action_id'] === 'pending-bol-001';
        });
    }

    public function test_confirmacao_de_excluir_fatura_langchain_e_delegada_ao_runtime(): void
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
                'message' => 'Exclui a fatura FAT-202603-5937 com sucesso.',
                'detalhes' => [
                    'resumo' => ['criados' => 1, 'erros' => 0],
                    'registros' => [
                        [
                            'id' => 900,
                            'numero_fatura' => 'FAT-202603-5937',
                        ],
                    ],
                    'erros_lista' => [],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chat/confirmar', [
            'acao' => 'excluir_fatura',
            'dados' => [
                [
                    'fatura_id' => 900,
                    'fatura_label' => 'FAT-202603-5937',
                ],
            ],
            'metadata' => [
                'fonte' => 'langchain-runtime',
                'runtime_pending_action_id' => 'pending-del-fat-001',
                'runtime_confirmation_strength' => 'strong',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('detalhes.registros.0.numero_fatura', 'FAT-202603-5937');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://langchain-runtime.test/chat/resume'
                && $request->hasHeader('X-Agent-Secret', 'runtime-secret')
                && $request['acao'] === 'excluir_fatura'
                && $request['metadata']['runtime_pending_action_id'] === 'pending-del-fat-001';
        });
    }

    public function test_confirmacao_langchain_com_pendencias_nao_retorna_erro_fatal(): void
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
                'success' => false,
                'message' => 'Consigo preparar a fatura, mas ainda preciso confirmar alguns dados. Me informe o cliente.',
                'detalhes' => [
                    'resumo' => ['criados' => 0, 'erros' => 0],
                    'registros' => [],
                    'erros_lista' => [],
                    'pendencias' => ['cliente'],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chat/confirmar', [
            'acao' => 'gerar_fatura',
            'dados' => [],
            'metadata' => [
                'fonte' => 'langchain-runtime',
                'runtime_draft_action_id' => 'draft-fat-001',
                'runtime_pending_fields' => ['cliente'],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('requires_more_info', true)
            ->assertJsonPath('detalhes.pendencias.0', 'cliente');
    }

    public function test_chat_langchain_processa_fluxo_condicional_de_fatura_na_mesma_sessao(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
        ]);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'http://langchain-runtime.test/chat' => Http::sequence()
                ->push([
                    'mensagem' => 'Preparei a fatura para confirmacao.',
                    'acao_sugerida' => 'gerar_fatura',
                    'dados_estruturados' => [
                        'tipo' => 'fatura',
                        'acao_sugerida' => 'gerar_fatura',
                        'dados_mapeados' => [[
                            'cliente_id' => 3,
                            'cliente' => 'DELTA MEDICINA OCUPACIONAL LTDA',
                            'periodo_referencia' => '2026-03',
                            'data_vencimento' => '2026-04-15',
                            'valor_total' => 1500.0,
                            'gerar_boleto' => true,
                            'emitir_nfse' => true,
                        ]],
                        'metadata' => [
                            'fonte' => 'langchain-runtime',
                            'runtime_pending_action_id' => 'pending-fat-100',
                            'runtime_requires_confirmation' => true,
                        ],
                    ],
                ], 200)
                ->push([
                    'mensagem' => 'Atualizei a solicitacao pendente com os novos dados.',
                    'acao_sugerida' => 'gerar_fatura',
                    'dados_estruturados' => [
                        'tipo' => 'fatura',
                        'acao_sugerida' => 'gerar_fatura',
                        'dados_mapeados' => [[
                            'cliente_id' => 3,
                            'cliente' => 'DELTA MEDICINA OCUPACIONAL LTDA',
                            'periodo_referencia' => '2026-03',
                            'data_vencimento' => '2026-04-20',
                            'valor_total' => 1500.0,
                            'gerar_boleto' => true,
                            'emitir_nfse' => false,
                        ]],
                        'metadata' => [
                            'fonte' => 'langchain-runtime',
                            'runtime_pending_action_id' => 'pending-fat-101',
                            'runtime_requires_confirmation' => true,
                        ],
                    ],
                ], 200)
                ->push([
                    'mensagem' => 'Gerei a fatura FAT-202603-4375 para DELTA MEDICINA OCUPACIONAL LTDA no valor de R$ 1.500,00 com vencimento em 20/04/2026 com boleto gerado.',
                    'acao_sugerida' => null,
                    'dados_estruturados' => null,
                ], 200),
        ]);

        $sessionId = 'sessao-condicional-http';

        $this->postJson('/api/chat/enviar', [
            'mensagem' => 'Gere uma fatura para DELTA MEDICINA OCUPACIONAL LTDA referente a março de 2026, com vencimento em 15/04/2026, item PCMSO mensal no valor de 1500 reais, com boleto e NFS-e.',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('acao_sugerida', 'gerar_fatura')
            ->assertJsonPath('dados_estruturados.metadata.runtime_requires_confirmation', true)
            ->assertJsonPath('dados_estruturados.dados_mapeados.0.data_vencimento', '2026-04-15');

        $this->postJson('/api/chat/enviar', [
            'mensagem' => 'pode seguir, mas muda o vencimento para 20/04/2026 e sem emitir a NFS-e',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('acao_sugerida', 'gerar_fatura')
            ->assertJsonPath('dados_estruturados.metadata.runtime_requires_confirmation', true)
            ->assertJsonPath('dados_estruturados.dados_mapeados.0.data_vencimento', '2026-04-20')
            ->assertJsonPath('dados_estruturados.dados_mapeados.0.emitir_nfse', false);

        $this->postJson('/api/chat/enviar', [
            'mensagem' => 'agora sim, pode seguir',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('acao_sugerida', null)
            ->assertJsonPath('dados_estruturados', null)
            ->assertJsonPath('content', 'Gerei a fatura FAT-202603-4375 para DELTA MEDICINA OCUPACIONAL LTDA no valor de R$ 1.500,00 com vencimento em 20/04/2026 com boleto gerado.');

        $requests = collect(Http::recorded())
            ->filter(fn (array $call) => $call[0]->url() === 'http://langchain-runtime.test/chat')
            ->values();

        $this->assertCount(3, $requests);

        $firstPayload = $requests[0][0]->data();
        $secondPayload = $requests[1][0]->data();
        $thirdPayload = $requests[2][0]->data();

        $this->assertSame($sessionId, $firstPayload['session_id']);
        $this->assertSame($sessionId, $secondPayload['session_id']);
        $this->assertSame($sessionId, $thirdPayload['session_id']);
        $this->assertCount(1, $firstPayload['historico']);
        $this->assertCount(3, $secondPayload['historico']);
        $this->assertCount(5, $thirdPayload['historico']);
        $this->assertSame('pode seguir, mas muda o vencimento para 20/04/2026 e sem emitir a NFS-e', $secondPayload['mensagem']);
        $this->assertSame('agora sim, pode seguir', $thirdPayload['mensagem']);

        $assistantMessages = ChatMessage::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $assistantMessages);
        $this->assertSame('Preparei a fatura para confirmacao.', $assistantMessages[0]->content);
        $this->assertSame('Atualizei a solicitacao pendente com os novos dados.', $assistantMessages[1]->content);
        $this->assertStringContainsString('FAT-202603-4375', $assistantMessages[2]->content);
    }

    public function test_chat_langchain_cadastra_cliente_e_retoma_fatura_automaticamente_na_mesma_sessao(): void
    {
        config([
            'chatbot.runtime.driver' => 'langchain',
            'chatbot.runtime.base_url' => 'http://langchain-runtime.test',
            'chatbot.runtime.secret' => 'runtime-secret',
        ]);

        $user = User::factory()->create([
            'ativo' => true,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            'http://langchain-runtime.test/chat' => Http::sequence()
                ->push([
                    'mensagem' => 'Nao encontrei esse cliente no cadastro. Preparei o cadastro do cliente extraido da planilha e, depois da confirmacao, continuo automaticamente a fatura.',
                    'acao_sugerida' => 'criar_cliente',
                    'dados_estruturados' => [
                        'tipo' => 'cliente',
                        'acao_sugerida' => 'criar_cliente',
                        'dados_mapeados' => [[
                            'cnpj' => '34567890000121',
                            'razao_social' => 'GAMMA MEDICINA OCUPACIONAL LTDA',
                            'nome_fantasia' => 'GAMMA MEDICINA OCUPACIONAL LTDA',
                            'status' => 'ativo',
                            '_resume_fatura_draft_action_id' => 'draft-fat-200',
                        ]],
                        'metadata' => [
                            'fonte' => 'langchain-runtime',
                            'runtime_pending_action_id' => 'pending-cli-200',
                            'runtime_requires_confirmation' => true,
                            'runtime_origin_action' => 'gerar_fatura',
                        ],
                    ],
                ], 200)
                ->push([
                    'mensagem' => 'O cliente GAMMA MEDICINA OCUPACIONAL LTDA foi cadastrado com sucesso. Tambem gerei automaticamente a fatura FAT-202603-6551 para GAMMA MEDICINA OCUPACIONAL LTDA no valor de R$ 1.500,00 com vencimento em 15/04/2026 com boleto gerado e NFS-e emitida.',
                    'acao_sugerida' => null,
                    'dados_estruturados' => null,
                ], 200),
        ]);

        $sessionId = 'sessao-cliente-retoma-fatura';

        $this->postJson('/api/chat/enviar', [
            'mensagem' => 'Cadastre e fature a Gamma Medicina Ocupacional LTDA, CNPJ 34.567.890/0001-21, referente a março de 2026, com vencimento em 15/04/2026, item PCMSO mensal no valor de 1500 reais. Gere também o boleto e emita a NFS-e.',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('acao_sugerida', 'criar_cliente')
            ->assertJsonPath('dados_estruturados.metadata.runtime_requires_confirmation', true)
            ->assertJsonPath('dados_estruturados.metadata.runtime_origin_action', 'gerar_fatura')
            ->assertJsonPath('dados_estruturados.dados_mapeados.0.razao_social', 'GAMMA MEDICINA OCUPACIONAL LTDA');

        $this->postJson('/api/chat/enviar', [
            'mensagem' => 'pode seguir',
            'session_id' => $sessionId,
        ])->assertOk()
            ->assertJsonPath('acao_sugerida', null)
            ->assertJsonPath('dados_estruturados', null)
            ->assertJsonPath('content', 'O cliente GAMMA MEDICINA OCUPACIONAL LTDA foi cadastrado com sucesso. Tambem gerei automaticamente a fatura FAT-202603-6551 para GAMMA MEDICINA OCUPACIONAL LTDA no valor de R$ 1.500,00 com vencimento em 15/04/2026 com boleto gerado e NFS-e emitida.');

        $requests = collect(Http::recorded())
            ->filter(fn (array $call) => $call[0]->url() === 'http://langchain-runtime.test/chat')
            ->values();

        $this->assertCount(2, $requests);

        $firstPayload = $requests[0][0]->data();
        $secondPayload = $requests[1][0]->data();

        $this->assertSame($sessionId, $firstPayload['session_id']);
        $this->assertSame($sessionId, $secondPayload['session_id']);
        $this->assertCount(1, $firstPayload['historico']);
        $this->assertCount(3, $secondPayload['historico']);
        $this->assertSame('pode seguir', $secondPayload['mensagem']);

        $assistantMessages = ChatMessage::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $assistantMessages);
        $this->assertStringContainsString('continuo automaticamente a fatura', $assistantMessages[0]->content);
        $this->assertStringContainsString('FAT-202603-6551', $assistantMessages[1]->content);
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
