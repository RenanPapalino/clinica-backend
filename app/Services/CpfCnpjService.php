<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CpfCnpjService
{
    public function consultarCpf(string $cpf): array
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11) {
            throw new \InvalidArgumentException('CPF inválido para consulta.');
        }

        $config = config('services.cpf_cnpj');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $timeout = (int) ($config['timeout'] ?? 60);

        if ($apiKey === '') {
            throw new \RuntimeException('Integração CPF.CNPJ não configurada.');
        }

        $response = Http::baseUrl(rtrim((string) ($config['base_url'] ?? 'https://api.cpfcnpj.com.br'), '/'))
            ->acceptJson()
            ->withToken($apiKey)
            ->timeout($timeout)
            ->get("/v1/cpf/{$cpf}");

        if ($response->failed()) {
            $message = data_get($response->json(), 'message')
                ?: 'Não foi possível consultar o CPF no provedor configurado.';
            throw new \RuntimeException($message);
        }

        return [
            'provider' => 'cpf_cnpj_v1',
            'payload' => $response->json(),
            'mapped' => $this->mapPayload($response->json(), $cpf),
        ];
    }

    private function mapPayload(array $payload, string $cpf): array
    {
        $data = data_get($payload, 'data', $payload);

        $status = Str::lower((string) (
            data_get($data, 'situacao')
            ?: data_get($data, 'status')
            ?: data_get($payload, 'situacao')
            ?: ''
        ));

        return [
            'cpf' => $cpf,
            'razao_social' => $this->normalizeText(
                data_get($data, 'nome')
                ?: data_get($data, 'name')
            ),
            'nome_fantasia' => null,
            'cep' => preg_replace('/\D/', '', (string) (
                data_get($data, 'cep')
                ?: data_get($data, 'address.zip')
                ?: ''
            )) ?: null,
            'logradouro' => $this->normalizeText(
                data_get($data, 'logradouro')
                ?: data_get($data, 'address.street')
            ),
            'numero' => $this->normalizeText(
                data_get($data, 'numero')
                ?: data_get($data, 'address.number')
            ),
            'complemento' => $this->normalizeText(
                data_get($data, 'complemento')
                ?: data_get($data, 'address.details')
            ),
            'bairro' => $this->normalizeText(
                data_get($data, 'bairro')
                ?: data_get($data, 'address.district')
            ),
            'cidade' => $this->normalizeText(
                data_get($data, 'cidade')
                ?: data_get($data, 'address.city')
            ),
            'uf' => Str::upper((string) (
                data_get($data, 'uf')
                ?: data_get($data, 'address.state')
                ?: ''
            )) ?: null,
            'observacoes' => $this->buildObservacoes($data),
            'status' => str_contains($status, 'regular') || str_contains($status, 'ativo') ? 'ativo' : null,
            'metadata' => [
                'situacao' => data_get($data, 'situacao') ?: data_get($data, 'status'),
                'nascimento' => data_get($data, 'nascimento') ?: data_get($data, 'birthdate'),
                'mae' => data_get($data, 'mae') ?: data_get($data, 'mother'),
                'genero' => data_get($data, 'genero') ?: data_get($data, 'gender'),
            ],
        ];
    }

    private function buildObservacoes(array $data): ?string
    {
        $notes = [];

        $situacao = data_get($data, 'situacao') ?: data_get($data, 'status');
        if ($situacao) {
            $notes[] = 'Situação cadastral: ' . $situacao;
        }

        $nascimento = data_get($data, 'nascimento') ?: data_get($data, 'birthdate');
        if ($nascimento) {
            $notes[] = 'Nascimento: ' . $nascimento;
        }

        $mae = data_get($data, 'mae') ?: data_get($data, 'mother');
        if ($mae) {
            $notes[] = 'Mãe: ' . $mae;
        }

        $genero = data_get($data, 'genero') ?: data_get($data, 'gender');
        if ($genero) {
            $notes[] = 'Gênero: ' . $genero;
        }

        return empty($notes) ? null : implode(' | ', $notes);
    }

    private function normalizeText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }
}
