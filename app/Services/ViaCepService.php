<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ViaCepService
{
    public function consultarCep(string $cep): array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            throw new \InvalidArgumentException('CEP inválido para consulta.');
        }

        $config = config('services.viacep');
        $timeout = (int) ($config['timeout'] ?? 10);

        $response = Http::baseUrl(rtrim((string) ($config['base_url'] ?? 'https://viacep.com.br/ws'), '/'))
            ->acceptJson()
            ->timeout($timeout)
            ->get("/{$cep}/json/");

        if ($response->failed()) {
            $message = data_get($response->json(), 'message')
                ?: 'Não foi possível consultar o CEP no ViaCEP.';
            throw new \RuntimeException($message);
        }

        $payload = $response->json();

        if (data_get($payload, 'erro') === true) {
            throw new \RuntimeException('CEP não encontrado no ViaCEP.');
        }

        return [
            'provider' => 'viacep',
            'payload' => $payload,
            'mapped' => [
                'cep' => preg_replace('/\D/', '', (string) (data_get($payload, 'cep') ?: $cep)) ?: null,
                'logradouro' => $this->normalizeText(data_get($payload, 'logradouro')),
                'complemento' => $this->normalizeText(data_get($payload, 'complemento')),
                'bairro' => $this->normalizeText(data_get($payload, 'bairro')),
                'cidade' => $this->normalizeText(data_get($payload, 'localidade')),
                'uf' => $this->normalizeText(data_get($payload, 'uf')),
                'metadata' => [
                    'ibge' => data_get($payload, 'ibge'),
                    'gia' => data_get($payload, 'gia'),
                    'ddd' => data_get($payload, 'ddd'),
                    'siafi' => data_get($payload, 'siafi'),
                ],
            ],
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }
}
