<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveFileMirrorService
{
    private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const OAUTH_REFRESH_GRANT_TYPE = 'refresh_token';

    public function isConfigured(): bool
    {
        return trim((string) config('services.google_drive.folder_id')) !== ''
            && $this->hasCredentialSource();
    }

    public function mirrorChatUpload(UploadedFile $file, array $context = []): array
    {
        $folderId = trim((string) config('services.google_drive.folder_id'));

        if ($folderId === '') {
            throw new RuntimeException('GOOGLE_DRIVE_FOLDER_ID não configurado.');
        }

        $accessToken = $this->fetchAccessToken();
        $metadata = $this->buildMetadata($file, $context, $folderId);
        $createdFile = $this->createRemoteFile($accessToken, $metadata);

        try {
            $uploadedFile = $this->uploadRemoteFileContent($accessToken, (string) $createdFile['id'], $file);
        } catch (\Throwable $exception) {
            $this->deleteRemoteFileQuietly($accessToken, (string) ($createdFile['id'] ?? ''));
            throw $exception;
        }

        return [
            'success' => true,
            'provider' => 'google_drive',
            'auth_mode' => $this->resolveAuthMode(),
            'message' => 'Arquivo enviado ao Google Drive para ingestão assíncrona.',
            'file_id' => $uploadedFile['id'] ?? $createdFile['id'] ?? null,
            'file_name' => $uploadedFile['name'] ?? $createdFile['name'] ?? $metadata['name'],
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $uploadedFile['mimeType'] ?? $createdFile['mimeType'] ?? $file->getClientMimeType(),
            'folder_id' => $folderId,
            'web_view_link' => $uploadedFile['webViewLink'] ?? $createdFile['webViewLink'] ?? null,
            'created_time' => $uploadedFile['createdTime'] ?? $createdFile['createdTime'] ?? null,
            'modified_time' => $uploadedFile['modifiedTime'] ?? $createdFile['modifiedTime'] ?? null,
        ];
    }

    private function hasCredentialSource(): bool
    {
        if ($this->hasOauthAccessTokenCredential()) {
            return true;
        }

        if ($this->hasOauthRefreshTokenCredentials()) {
            return true;
        }

        $json = trim((string) config('services.google_drive.service_account_json'));
        $path = trim((string) config('services.google_drive.service_account_path'));

        return $json !== '' || ($path !== '' && is_file($path));
    }

    private function hasOauthAccessTokenCredential(): bool
    {
        return trim((string) config('services.google_drive.oauth_access_token')) !== '';
    }

    private function hasOauthRefreshTokenCredentials(): bool
    {
        return trim((string) config('services.google_drive.oauth_client_id')) !== ''
            && trim((string) config('services.google_drive.oauth_client_secret')) !== ''
            && trim((string) config('services.google_drive.oauth_refresh_token')) !== '';
    }

    private function resolveAuthMode(): string
    {
        if ($this->hasOauthAccessTokenCredential()) {
            return 'oauth_access_token';
        }

        return $this->hasOauthRefreshTokenCredentials()
            ? 'oauth_refresh_token'
            : 'service_account';
    }

    private function loadServiceAccountCredentials(): array
    {
        $json = trim((string) config('services.google_drive.service_account_json'));
        $path = trim((string) config('services.google_drive.service_account_path'));

        if ($json === '' && $path !== '') {
            if (!is_file($path)) {
                throw new RuntimeException('Arquivo de credenciais do Google Drive não encontrado.');
            }

            $json = (string) file_get_contents($path);
        }

        if ($json === '') {
            throw new RuntimeException('Credenciais do Google Drive não configuradas.');
        }

        $credentials = json_decode($json, true);
        if (!is_array($credentials)) {
            throw new RuntimeException('JSON de credenciais do Google Drive inválido.');
        }

        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('Credenciais do Google Drive incompletas.');
        }

        $credentials['private_key'] = str_replace(["\\n", "\r\n", "\r"], ["\n", "\n", "\n"], (string) $credentials['private_key']);

        return $credentials;
    }

    private function fetchAccessToken(): string
    {
        return match ($this->resolveAuthMode()) {
            'oauth_access_token' => $this->fetchConfiguredOauthAccessToken(),
            'oauth_refresh_token' => $this->fetchOauthAccessToken(),
            default => $this->fetchServiceAccountAccessToken($this->loadServiceAccountCredentials()),
        };
    }

    private function fetchConfiguredOauthAccessToken(): string
    {
        $token = trim((string) config('services.google_drive.oauth_access_token'));

        if ($token === '') {
            throw new RuntimeException('Access token OAuth do Google Drive não configurado.');
        }

        return $token;
    }

    private function fetchServiceAccountAccessToken(array $credentials): string
    {
        $tokenUrl = trim((string) config('services.google_drive.token_url'));
        $scope = trim((string) config('services.google_drive.scope'));

        $assertion = $this->buildJwtAssertion(
            clientEmail: (string) $credentials['client_email'],
            privateKey: (string) $credentials['private_key'],
            audience: $tokenUrl,
            scope: $scope,
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($tokenUrl, [
                'grant_type' => self::JWT_GRANT_TYPE,
                'assertion' => $assertion,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao autenticar no Google Drive: HTTP ' . $response->status() . '.');
        }

        $token = $response->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Resposta de autenticação do Google Drive sem access_token.');
        }

        return $token;
    }

    private function fetchOauthAccessToken(): string
    {
        $clientId = trim((string) config('services.google_drive.oauth_client_id'));
        $clientSecret = trim((string) config('services.google_drive.oauth_client_secret'));
        $refreshToken = trim((string) config('services.google_drive.oauth_refresh_token'));
        $tokenUrl = trim((string) config('services.google_drive.token_url'));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Credenciais OAuth do Google Drive incompletas.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($tokenUrl, [
                'grant_type' => self::OAUTH_REFRESH_GRANT_TYPE,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao autenticar no Google Drive via OAuth: HTTP ' . $response->status() . '.');
        }

        $token = $response->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Resposta de autenticação OAuth do Google Drive sem access_token.');
        }

        return $token;
    }

    private function buildJwtAssertion(string $clientEmail, string $privateKey, string $audience, string $scope): string
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + 3600;

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $payload;
        $signature = '';

        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar JWT para o Google Drive.');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function createRemoteFile(string $accessToken, array $metadata): array
    {
        $baseUrl = rtrim((string) config('services.google_drive.files_url'), '/');
        $query = http_build_query([
            'supportsAllDrives' => 'true',
            'fields' => 'id,name,mimeType,parents,webViewLink,createdTime,modifiedTime',
        ]);

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout($this->timeout())
            ->post($baseUrl . '?' . $query, $metadata);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao criar arquivo no Google Drive: HTTP ' . $response->status() . '.');
        }

        $body = $response->json();
        if (!is_array($body) || empty($body['id'])) {
            throw new RuntimeException('Google Drive não retornou o id do arquivo criado.');
        }

        return $body;
    }

    private function uploadRemoteFileContent(string $accessToken, string $fileId, UploadedFile $file): array
    {
        $uploadBaseUrl = rtrim((string) config('services.google_drive.upload_base_url'), '/');
        $query = http_build_query([
            'uploadType' => 'media',
            'supportsAllDrives' => 'true',
            'fields' => 'id,name,mimeType,parents,webViewLink,createdTime,modifiedTime',
        ]);

        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        $content = (string) file_get_contents($file->getRealPath());

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout($this->timeout())
            ->withBody($content, $mimeType)
            ->patch($uploadBaseUrl . '/' . $fileId . '?' . $query);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao enviar conteúdo do arquivo para o Google Drive: HTTP ' . $response->status() . '.');
        }

        $body = $response->json();
        if (!is_array($body) || empty($body['id'])) {
            throw new RuntimeException('Google Drive não retornou o id do arquivo após upload.');
        }

        return $body;
    }

    private function deleteRemoteFileQuietly(string $accessToken, string $fileId): void
    {
        if ($fileId === '') {
            return;
        }

        $baseUrl = rtrim((string) config('services.google_drive.files_url'), '/');
        $query = http_build_query([
            'supportsAllDrives' => 'true',
        ]);

        try {
            Http::withToken($accessToken)
                ->timeout($this->timeout())
                ->delete($baseUrl . '/' . $fileId . '?' . $query);
        } catch (\Throwable) {
        }
    }

    private function buildMetadata(UploadedFile $file, array $context, string $folderId): array
    {
        $timestamp = now()->format('Ymd_His');
        $sessionId = Str::slug((string) ($context['session_id'] ?? 'sessao'), '_');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBaseName = Str::slug(Str::limit($baseName, 80, ''), '_');
        $prefix = Str::slug((string) config('chatbot.chat_upload.drive_name_prefix', 'chat-upload'), '_');
        $targetName = trim($prefix . '_u' . $userId . '_' . $sessionId . '_' . $timestamp . '_' . $safeBaseName, '_');

        if ($extension !== '') {
            $targetName .= '.' . $extension;
        }

        $description = json_encode([
            'source' => 'medintelligence_chat_upload',
            'session_id' => (string) ($context['session_id'] ?? ''),
            'user_id' => $userId,
            'tipo_processamento' => (string) ($context['tipo_processamento'] ?? 'auto'),
            'uploaded_at' => now()->toISOString(),
            'original_name' => $originalName,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'name' => $targetName,
            'parents' => [$folderId],
            'description' => $description,
            'appProperties' => array_filter([
                'source' => 'medintelligence_chat_upload',
                'session_id' => (string) ($context['session_id'] ?? ''),
                'user_id' => $userId,
                'tipo_processamento' => (string) ($context['tipo_processamento'] ?? 'auto'),
            ], static fn ($value) => $value !== ''),
        ];
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.google_drive.timeout', 30));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
