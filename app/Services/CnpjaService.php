<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CnpjaService
{
    public function consultarCnpj(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) {
            throw new \InvalidArgumentException('CNPJ inválido para consulta.');
        }

        $config = config('services.cnpja');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $timeout = (int) ($config['timeout'] ?? 15);
        $includeSimples = (bool) ($config['include_simples'] ?? true);

        $query = [
            'simples' => $includeSimples ? 'true' : 'false',
        ];

        if ($apiKey !== '') {
            $query['strategy'] = $config['strategy'] ?? 'CACHE_IF_ERROR';
            $query['maxAge'] = (int) ($config['max_age'] ?? 7);
            $query['maxStale'] = (int) ($config['max_stale'] ?? 30);

            $response = Http::baseUrl(rtrim((string) ($config['base_url'] ?? 'https://api.cnpja.com'), '/'))
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => $apiKey,
                ])
                ->timeout($timeout)
                ->get("/office/{$cnpj}", $query);

            if ($response->failed()) {
                $message = data_get($response->json(), 'message')
                    ?: 'Não foi possível consultar o CNPJ na CNPJá.';
                throw new \RuntimeException($message);
            }

            return [
                'provider' => 'cnpja_commercial',
                'payload' => $response->json(),
                'mapped' => $this->mapPayload($response->json(), $cnpj),
            ];
        }

        $response = Http::baseUrl(rtrim((string) ($config['public_base_url'] ?? 'https://open.cnpja.com'), '/'))
            ->acceptJson()
            ->timeout($timeout)
            ->get("/office/{$cnpj}");

        if ($response->failed()) {
            $message = data_get($response->json(), 'message')
                ?: 'Não foi possível consultar o CNPJ na CNPJá.';
            throw new \RuntimeException($message);
        }

        return [
            'provider' => 'cnpja_public',
            'payload' => $response->json(),
            'mapped' => $this->mapPayload($response->json(), $cnpj),
        ];
    }

    private function mapPayload(array $payload, string $cnpj): array
    {
        $emails = collect(data_get($payload, 'emails', []))
            ->map(function ($item) {
                if (is_string($item)) {
                    return trim($item);
                }

                return trim((string) (data_get($item, 'address')
                    ?: data_get($item, 'email')
                    ?: data_get($item, 'value')
                    ?: ''));
            })
            ->filter()
            ->values();

        $phones = collect(data_get($payload, 'phones', data_get($payload, 'telefones', [])))
            ->map(function ($item) {
                if (is_string($item)) {
                    return preg_replace('/\D/', '', $item);
                }

                $ddd = (string) (data_get($item, 'area') ?: data_get($item, 'ddd') ?: '');
                $number = (string) (data_get($item, 'number') ?: data_get($item, 'numero') ?: '');
                return preg_replace('/\D/', '', $ddd . $number);
            })
            ->filter()
            ->values();

        $registrations = collect(data_get($payload, 'registrations', []));
        $originState = (string) (data_get($payload, 'address.state')
            ?: data_get($payload, 'uf')
            ?: '');

        $registration = $registrations->first(function ($item) use ($originState) {
            $state = (string) (data_get($item, 'state') ?: data_get($item, 'uf') ?: '');
            return $originState !== '' && $state === $originState;
        }) ?: $registrations->first();

        $simples = data_get($payload, 'company.simples') ?: data_get($payload, 'simples');
        $simei = data_get($payload, 'company.simei') ?: data_get($payload, 'simei');
        $status = Str::lower((string) (data_get($payload, 'status.text')
            ?: data_get($payload, 'status')
            ?: data_get($payload, 'situacao_cadastral')
            ?: ''));

        return [
            'cnpj' => $cnpj,
            'razao_social' => $this->normalizeText(
                data_get($payload, 'company.name')
                ?: data_get($payload, 'razao_social')
                ?: data_get($payload, 'name')
            ),
            'nome_fantasia' => $this->normalizeText(
                data_get($payload, 'alias')
                ?: data_get($payload, 'company.alias')
                ?: data_get($payload, 'nome_fantasia')
            ),
            'email' => $emails->first(),
            'telefone' => $phones->first(),
            'cep' => preg_replace('/\D/', '', (string) (data_get($payload, 'address.zip') ?: data_get($payload, 'cep') ?: '')) ?: null,
            'logradouro' => $this->normalizeText(
                data_get($payload, 'address.street')
                ?: data_get($payload, 'logradouro')
            ),
            'numero' => $this->normalizeText(
                data_get($payload, 'address.number')
                ?: data_get($payload, 'numero')
            ),
            'complemento' => $this->normalizeText(
                data_get($payload, 'address.details')
                ?: data_get($payload, 'complemento')
            ),
            'bairro' => $this->normalizeText(
                data_get($payload, 'address.district')
                ?: data_get($payload, 'bairro')
            ),
            'cidade' => $this->normalizeText(
                data_get($payload, 'address.city')
                ?: data_get($payload, 'municipio')
                ?: data_get($payload, 'cidade')
            ),
            'uf' => Str::upper((string) (data_get($payload, 'address.state') ?: data_get($payload, 'uf') ?: '')) ?: null,
            'inscricao_estadual' => $this->normalizeText(
                data_get($registration, 'number')
                ?: data_get($registration, 'inscricao_estadual')
            ),
            'inscricao_municipal' => $this->normalizeText(
                data_get($payload, 'company.municipalRegistration')
                ?: data_get($payload, 'inscricao_municipal')
            ),
            'site' => $this->normalizeText(
                data_get($payload, 'company.website')
                ?: data_get($payload, 'site')
            ),
            'observacoes' => $this->buildObservacoes($payload, $simples, $simei),
            'status' => str_contains($status, 'ativa') || str_contains($status, 'active') ? 'ativo' : null,
            'metadata' => [
                'provider' => data_get($payload, 'provider'),
                'status_text' => data_get($payload, 'status.text') ?: data_get($payload, 'situacao_cadastral'),
                'founded' => data_get($payload, 'founded') ?: data_get($payload, 'data_inicio_atividade'),
                'company_size' => data_get($payload, 'company.size.text') ?: data_get($payload, 'porte_empresa'),
                'legal_nature' => data_get($payload, 'company.nature.text') ?: data_get($payload, 'natureza_juridica'),
            ],
        ];
    }

    private function buildObservacoes(array $payload, mixed $simples, mixed $simei): ?string
    {
        $notes = [];

        $status = data_get($payload, 'status.text') ?: data_get($payload, 'situacao_cadastral');
        if ($status) {
            $notes[] = 'Situação cadastral: ' . $status;
        }

        $legalNature = data_get($payload, 'company.nature.text') ?: data_get($payload, 'natureza_juridica');
        if ($legalNature) {
            $notes[] = 'Natureza jurídica: ' . $legalNature;
        }

        $size = data_get($payload, 'company.size.text') ?: data_get($payload, 'porte_empresa');
        if ($size) {
            $notes[] = 'Porte: ' . $size;
        }

        if (is_array($simples) && array_key_exists('optant', $simples)) {
            $notes[] = 'Simples Nacional: ' . (data_get($simples, 'optant') ? 'Sim' : 'Não');
        } elseif ($simples !== null) {
            $notes[] = 'Simples Nacional: ' . (is_string($simples) ? $simples : 'Sim');
        }

        if (is_array($simei) && array_key_exists('optant', $simei)) {
            $notes[] = 'MEI: ' . (data_get($simei, 'optant') ? 'Sim' : 'Não');
        }

        return empty($notes) ? null : implode(' | ', $notes);
    }

    private function normalizeText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }
}
