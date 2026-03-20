<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatGatewayService
{
    public function processarChat(array $payload, bool $isArquivo = false): array
    {
        $provider = strtolower((string) config('chatbot.runtime.driver', env('AI_CHAT_PROVIDER', 'langchain')));
        $timeout = $isArquivo
            ? (int) env('AI_CHAT_FILE_TIMEOUT', 600)
            : (int) env('AI_CHAT_TIMEOUT', 120);

        $url = $this->resolverUrl($provider, $isArquivo);

        if (!$url) {
            return [
                'success' => false,
                'provider' => $provider,
                'message' => "Webhook {$provider} não configurado.",
                'body' => null,
            ];
        }

        try {
            $request = Http::acceptJson()
                ->timeout($timeout);

            $secret = $this->resolverSecret($provider);
            if ($secret) {
                $request = match ($provider) {
                    'langchain' => $request
                        ->withToken($secret)
                        ->withHeaders(['X-Agent-Secret' => $secret]),
                    'n8n' => $request->withToken($secret),
                    default => $request,
                };
            }

            $response = $request->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Falha no gateway de IA', [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'provider' => $provider,
                    'message' => "Erro ao processar no provider {$provider} (HTTP {$response->status()}).",
                    'body' => $this->decodeBody($response),
                ];
            }

            return [
                'success' => true,
                'provider' => $provider,
                'message' => null,
                'body' => $this->decodeBody($response),
            ];
        } catch (\Throwable $e) {
            Log::error('Exceção no gateway de IA', [
                'provider' => $provider,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $provider,
                'message' => "Falha de conexão com {$provider}.",
                'body' => null,
            ];
        }
    }

    public function confirmarAcao(array $payload): array
    {
        $provider = strtolower((string) config('chatbot.runtime.driver', env('AI_CHAT_PROVIDER', 'langchain')));
        $url = $this->resolverConfirmationUrl($provider);

        if (!$url) {
            return [
                'success' => false,
                'provider' => $provider,
                'message' => "Provider {$provider} não suporta confirmação externa.",
                'body' => null,
            ];
        }

        try {
            $request = Http::acceptJson()->timeout((int) env('AI_CHAT_TIMEOUT', 120));

            $secret = $this->resolverSecret($provider);
            if ($secret) {
                $request = match ($provider) {
                    'langchain' => $request
                        ->withToken($secret)
                        ->withHeaders(['X-Agent-Secret' => $secret]),
                    'n8n' => $request->withToken($secret),
                    default => $request,
                };
            }

            $response = $request->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Falha na confirmação do gateway de IA', [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'provider' => $provider,
                    'message' => "Erro ao confirmar ação no provider {$provider} (HTTP {$response->status()}).",
                    'body' => $this->decodeBody($response),
                ];
            }

            return [
                'success' => true,
                'provider' => $provider,
                'message' => null,
                'body' => $this->decodeBody($response),
            ];
        } catch (\Throwable $e) {
            Log::error('Exceção na confirmação do gateway de IA', [
                'provider' => $provider,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $provider,
                'message' => "Falha de conexão ao confirmar ação em {$provider}.",
                'body' => null,
            ];
        }
    }

    private function resolverUrl(string $provider, bool $isArquivo): ?string
    {
        return match ($provider) {
            'langchain' => $this->resolverLangChainUrl($isArquivo),
            'n8n' => $isArquivo
                ? env('N8N_WEBHOOK_URL')
                : env('N8N_WEBHOOK_CHAT_URL'),
            default => null,
        };
    }

    private function resolverSecret(string $provider): ?string
    {
        return match ($provider) {
            'langchain' => config('chatbot.runtime.secret') ?: env('LANGCHAIN_API_KEY'),
            'n8n' => env('N8N_WEBHOOK_TOKEN'),
            default => null,
        };
    }

    private function resolverLangChainUrl(bool $isArquivo): ?string
    {
        $baseUrl = rtrim((string) config('chatbot.runtime.base_url'), '/');
        if ($baseUrl !== '') {
            return $baseUrl . ($isArquivo ? '/chat/file' : '/chat');
        }

        return $isArquivo
            ? env('LANGCHAIN_FILE_URL') ?: env('LANGCHAIN_CHAT_URL')
            : env('LANGCHAIN_CHAT_URL');
    }

    private function resolverConfirmationUrl(string $provider): ?string
    {
        return match ($provider) {
            'langchain' => ($baseUrl = rtrim((string) config('chatbot.runtime.base_url'), '/')) !== ''
                ? $baseUrl . '/chat/resume'
                : null,
            default => null,
        };
    }

    private function decodeBody(Response $response): mixed
    {
        return $response->json() ?? json_decode($response->body(), true) ?? $response->body();
    }
}
