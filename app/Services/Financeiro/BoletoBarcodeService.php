<?php

namespace App\Services\Financeiro;

use Carbon\Carbon;
use InvalidArgumentException;

class BoletoBarcodeService
{
    public function analisar(string $codigo): array
    {
        $digits = preg_replace('/\D/', '', $codigo);

        if (!in_array(strlen($digits), [44, 47, 48], true)) {
            throw new InvalidArgumentException('Informe uma linha digitável ou código de barras com 44, 47 ou 48 dígitos.');
        }

        if (strlen($digits) === 47) {
            $barcode = $this->linhaDigitavelBancoParaCodigoBarras($digits);
            return [
                'tipo' => 'boleto_bancario',
                'codigo_barras' => $barcode,
                'linha_digitavel' => $digits,
                'valor' => $this->parseValorBanco($barcode),
                'data_vencimento' => $this->parseDataVencimentoBanco($barcode),
                'banco' => substr($barcode, 0, 3),
                'descricao' => 'Boleto bancário',
            ];
        }

        if (strlen($digits) === 44) {
            return [
                'tipo' => 'boleto_bancario',
                'codigo_barras' => $digits,
                'linha_digitavel' => $digits,
                'valor' => $this->parseValorBanco($digits),
                'data_vencimento' => $this->parseDataVencimentoBanco($digits),
                'banco' => substr($digits, 0, 3),
                'descricao' => 'Boleto bancário',
            ];
        }

        return [
            'tipo' => 'arrecadacao',
            'codigo_barras' => $digits,
            'linha_digitavel' => $digits,
            'valor' => $this->parseValorArrecadacao($digits),
            'data_vencimento' => null,
            'banco' => null,
            'descricao' => 'Conta de arrecadação/concessionária',
        ];
    }

    private function linhaDigitavelBancoParaCodigoBarras(string $linha): string
    {
        return substr($linha, 0, 4)
            . substr($linha, 32, 1)
            . substr($linha, 33, 14)
            . substr($linha, 4, 5)
            . substr($linha, 10, 10)
            . substr($linha, 21, 10);
    }

    private function parseValorBanco(string $barcode): ?float
    {
        $valor = substr($barcode, 9, 10);
        if (!ctype_digit($valor)) {
            return null;
        }

        return round(((int) $valor) / 100, 2);
    }

    private function parseDataVencimentoBanco(string $barcode): ?string
    {
        $factor = (int) substr($barcode, 5, 4);
        if ($factor <= 0) {
            return null;
        }

        // Após fevereiro/2025 o fator foi reiniciado a partir de 1000.
        if ($factor >= 1000) {
            return Carbon::create(2025, 2, 22)->addDays($factor - 1000)->format('Y-m-d');
        }

        return Carbon::create(1997, 10, 7)->addDays($factor)->format('Y-m-d');
    }

    private function parseValorArrecadacao(string $digits): ?float
    {
        $identificadorValor = (int) substr($digits, 2, 1);
        if (!in_array($identificadorValor, [6, 7, 8, 9], true)) {
            return null;
        }

        $valor = substr($digits, 4, 11);
        if (!ctype_digit($valor)) {
            return null;
        }

        return round(((int) $valor) / 100, 2);
    }
}
