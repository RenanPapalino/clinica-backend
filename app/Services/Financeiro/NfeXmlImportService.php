<?php

namespace App\Services\Financeiro;

use InvalidArgumentException;
use SimpleXMLElement;

class NfeXmlImportService
{
    public function analisar(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if (!$xml instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('Não foi possível interpretar o XML da nota fiscal.');
        }

        $numeroNota = $this->xpathValue($xml, '//*[local-name()="ide"]/*[local-name()="nNF"]');
        $dataEmissao =
            $this->formatDate(
                $this->xpathValue($xml, '//*[local-name()="ide"]/*[local-name()="dhEmi"]')
                ?: $this->xpathValue($xml, '//*[local-name()="ide"]/*[local-name()="dEmi"]')
            );
        $dataVencimento =
            $this->formatDate(
                $this->xpathValue($xml, '//*[local-name()="dup"]/*[local-name()="dVenc"]')
                ?: $this->xpathValue($xml, '//*[local-name()="fat"]/*[local-name()="dVenc"]')
            );
        $valorTotal = $this->toDecimal(
            $this->xpathValue($xml, '//*[local-name()="total"]//*[local-name()="vNF"]')
        );

        $fornecedor = [
            'cnpj' => preg_replace('/\D/', '', (string) $this->xpathValue($xml, '//*[local-name()="emit"]/*[local-name()="CNPJ"]')),
            'razao_social' => $this->xpathValue($xml, '//*[local-name()="emit"]/*[local-name()="xNome"]'),
            'nome_fantasia' => $this->xpathValue($xml, '//*[local-name()="emit"]/*[local-name()="xFant"]'),
            'inscricao_estadual' => $this->xpathValue($xml, '//*[local-name()="emit"]/*[local-name()="IE"]'),
            'email' => $this->xpathValue($xml, '//*[local-name()="emit"]/*[local-name()="email"]'),
            'telefone' => preg_replace('/\D/', '', (string) $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="fone"]')),
            'cep' => preg_replace('/\D/', '', (string) $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="CEP"]')),
            'logradouro' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="xLgr"]'),
            'numero' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="nro"]'),
            'complemento' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="xCpl"]'),
            'bairro' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="xBairro"]'),
            'cidade' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="xMun"]'),
            'uf' => $this->xpathValue($xml, '//*[local-name()="enderEmit"]/*[local-name()="UF"]'),
        ];

        $itens = $xml->xpath('//*[local-name()="det"]/*[local-name()="prod"]/*[local-name()="xProd"]') ?: [];
        $itensResumo = collect($itens)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->take(4)
            ->values()
            ->all();

        $descricao = 'NF-e';
        if ($numeroNota) {
            $descricao .= ' ' . $numeroNota;
        }
        if (!empty($fornecedor['razao_social'])) {
            $descricao .= ' - ' . $fornecedor['razao_social'];
        }

        return [
            'documento_tipo' => 'nfe_xml',
            'numero_documento' => $numeroNota ?: null,
            'valor_total' => $valorTotal,
            'data_vencimento' => $dataVencimento ?: $dataEmissao,
            'data_emissao' => $dataEmissao,
            'descricao' => trim($descricao),
            'codigo_barras' => null,
            'cnpj_fornecedor' => $fornecedor['cnpj'] ?: null,
            'nome_fornecedor' => $fornecedor['razao_social'] ?: null,
            'fornecedor' => array_filter($fornecedor, fn ($value) => $value !== '' && $value !== null),
            'observacoes' => !empty($itensResumo)
                ? 'Itens da nota: ' . implode(' | ', $itensResumo)
                : null,
        ];
    }

    private function xpathValue(SimpleXMLElement $xml, string $path): ?string
    {
        $result = $xml->xpath($path);
        if (!$result || !isset($result[0])) {
            return null;
        }

        $value = trim((string) $result[0]);
        return $value !== '' ? $value : null;
    }

    private function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) str_replace(',', '.', $value), 2);
    }
}
