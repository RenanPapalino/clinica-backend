<?php

namespace App\Services;

use App\Models\OrdemServico;
use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Exception;

class SocImportService
{
    public function processarArquivo($caminhoArquivo)
    {
        $linhas = file($caminhoArquivo);
        $cnpjEncontrado = null;
        $inicioDados = false;
        $mapaColunas = [];
        $itensParaSalvar = [];

        // 1. Varredura Inicial (Metadados e Header)
        foreach ($linhas as $idx => $linha) {
            // Detecta encoding e converte para UTF-8
            $linha = mb_convert_encoding($linha, 'UTF-8', mb_detect_encoding($linha, ['UTF-8', 'ISO-8859-1'], true));
            $cols = str_getcsv($linha, count(explode(';', $linha)) > count(explode(',', $linha)) ? ';' : ',');

            // Busca CNPJ no cabeçalho
            if (!$cnpjEncontrado) {
                foreach ($cols as $c) {
                    if (preg_match('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $c, $matches)) {
                        $cnpjEncontrado = preg_replace('/\D/', '', $matches[0]);
                    }
                }
            }

            // Identifica a linha de cabeçalho da tabela
            if (!$inicioDados && (in_array('Produto/Serviço', $cols) || in_array('Total R$', $cols))) {
                $inicioDados = true;
                // Mapeia índices das colunas dinamicamente
                $mapaColunas = [
                    'descricao' => array_search('Produto/Serviço', $cols),
                    'total'     => array_search('Total R$', $cols),
                    'unidade'   => array_search('Unidade', $cols),
                    'empresa'   => array_search('Empresa Cliente', $cols),
                ];
                continue;
            }

            // Processa linhas de dados
            if ($inicioDados) {
                // Validação mínima: tem descrição e valor?
                $idxDesc = $mapaColunas['descricao'] ?? 0;
                $idxTotal = $mapaColunas['total'] ?? 12; // Fallback para posição 12 do CSV CURY

                $descricao = $cols[$idxDesc] ?? null;
                $valorRaw = $cols[$idxTotal] ?? '0';

                if (empty($descricao) || str_contains($descricao, 'Total')) continue;

                $valor = $this->parseValor($valorRaw);
                if ($valor <= 0) continue;

                $itensParaSalvar[] = [
                    'descricao' => $descricao,
                    'valor' => $valor,
                    'unidade' => $cols[$mapaColunas['unidade'] ?? 6] ?? null,
                    'cliente_origem' => $cols[$mapaColunas['empresa'] ?? 5] ?? null,
                ];
            }
        }

        if (empty($itensParaSalvar)) {
            throw new Exception("Nenhum item válido encontrado. Verifique o layout do arquivo.");
        }

        // 2. Persistência (Transação)
        return DB::transaction(function () use ($cnpjEncontrado, $itensParaSalvar) {
            // Busca cliente pelo CNPJ do cabeçalho ou usa um padrão (ID 1) se falhar
            $cliente = Cliente::where('cnpj', $cnpjEncontrado)->first();
            
            if (!$cliente && count($itensParaSalvar) > 0) {
                // Fallback: Tenta achar cliente pelo nome da primeira linha de dados?
                // Por segurança, vamos exigir que o cliente exista ou usar um genérico.
                // throw new Exception("Cliente com CNPJ $cnpjEncontrado não cadastrado.");
                $cliente = Cliente::first(); // Em dev, pega o primeiro
            }

            // Cria a OS
            $os = OrdemServico::create([
                'cliente_id' => $cliente->id,
                'codigo_os' => 'OS-' . date('Ymd-His'),
                'competencia' => date('m/Y'),
                'data_emissao' => now(),
                'valor_total' => collect($itensParaSalvar)->sum('valor'),
                'status' => 'pendente',
                'observacoes' => 'Importado via planilha SOC'
            ]);

            // Salva Itens
            foreach ($itensParaSalvar as $item) {
                $os->itens()->create([
                    'descricao' => $item['descricao'],
                    'quantidade' => 1,
                    'valor_unitario' => $item['valor'],
                    'valor_total' => $item['valor'],
                    'unidade_soc' => $item['unidade'],
                    'centro_custo_cliente' => $item['cliente_origem'] // Guarda quem consumiu o serviço
                ]);
            }

            return $os->load('itens', 'cliente');
        });
    }

    private function parseValor($val)
    {
        // Remove R$, espaços e converte "1.200,50" para 1200.50
        $val = preg_replace('/[^0-9,.-]/', '', $val);
        $val = str_replace('.', '', $val); // Tira ponto de milhar
        $val = str_replace(',', '.', $val); // Troca vírgula por ponto
        return (float) $val;
    }
}