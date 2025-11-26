<?php

namespace App\Services;

use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\OrdemServicoRateio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Exception;
use Carbon\Carbon;

class SocImportService
{
    public function importar(UploadedFile $file, $clienteId)
    {
        $path = $file->getRealPath();
        // Detectar encoding para evitar caracteres estranhos
        $content = file_get_contents($path);
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1'], true);
        
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = explode(PHP_EOL, $content);
        
        // Mapeamento dinâmico
        $headerMap = [];
        $headerFound = false;
        $itensParaSalvar = [];
        $resumoRateio = [];
        $valorTotalOS = 0;

        DB::beginTransaction();

        try {
            foreach ($lines as $lineIndex => $line) {
                if (trim($line) === '') continue;

                // Lê o CSV com ponto e vírgula (padrão SOC exportação BR)
                $row = str_getcsv($line, ';');

                // 1. Procura a linha de cabeçalho real
                if (!$headerFound) {
                    // Verifica se a linha contém as colunas chaves
                    if (in_array('Nome do Funcionário', $row) && in_array('Valor', $row)) {
                        $headerMap = array_flip($row); // Mapeia: 'Nome' => index 2, 'Valor' => index 6
                        $headerFound = true;
                    }
                    continue;
                }

                // 2. Processa as linhas de dados
                // Pula se não tiver nome de funcionário ou for linha de total
                $nomeFuncionario = $this->getData($row, $headerMap, 'Nome do Funcionário');
                if (empty($nomeFuncionario) || stripos($nomeFuncionario, 'Total') !== false) continue;

                $exame = $this->getData($row, $headerMap, 'Exame');
                if (empty($exame)) $exame = $this->getData($row, $headerMap, 'Serviço', 'Serviço SOC');

                $valorBruto = $this->getData($row, $headerMap, 'Valor', '0');
                $valor = $this->parseMoney($valorBruto);
                
                // Se valor for zero, ignorar (opcional)
                if ($valor <= 0) continue;

                $centroCusto = $this->getData($row, $headerMap, 'Centro de Custo', 'Geral');
                $dataRealizacao = $this->getData($row, $headerMap, 'Data');

                // Prepara o Item
                $itensParaSalvar[] = [
                    'descricao' => "$exame - $nomeFuncionario",
                    'quantidade' => 1,
                    'valor_unitario' => $valor,
                    'valor_total' => $valor,
                    'centro_custo' => $centroCusto,
                    'data_realizacao' => $this->parseDate($dataRealizacao)
                ];

                // Acumula Rateio
                if (!isset($resumoRateio[$centroCusto])) {
                    $resumoRateio[$centroCusto] = 0;
                }
                $resumoRateio[$centroCusto] += $valor;
                $valorTotalOS += $valor;
            }

            if (empty($itensParaSalvar)) {
                throw new Exception("Nenhum dado válido encontrado. Verifique se o arquivo é um CSV separado por ponto e vírgula (;).");
            }

            // 3. Cria a OS
            $os = OrdemServico::create([
                'cliente_id' => $clienteId,
                'codigo_os' => 'IMP-' . date('Ymd-His'),
                'competencia' => now()->format('m/Y'),
                'data_emissao' => now(),
                'status' => 'pendente',
                'valor_total' => $valorTotalOS,
                'observacoes' => 'Importado via Planilha SOC: ' . $file->getClientOriginalName()
            ]);

            // 4. Salva Itens
            foreach ($itensParaSalvar as $item) {
                $item['ordem_servico_id'] = $os->id;
                OrdemServicoItem::create($item);
            }

            // 5. Salva Rateios (Fundamental para o Financeiro)
            foreach ($resumoRateio as $cc => $val) {
                OrdemServicoRateio::create([
                    'ordem_servico_id' => $os->id,
                    'centro_custo' => $cc,
                    'valor' => $val,
                    'percentual' => ($valorTotalOS > 0) ? ($val / $valorTotalOS * 100) : 0
                ]);
            }

            DB::commit();

            return [
                'os_id' => $os->id,
                'total_itens' => count($itensParaSalvar),
                'valor_total' => $valorTotalOS,
                'rateio' => $resumoRateio
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getData($row, $map, $key, $default = '') {
        return isset($map[$key]) && isset($row[$map[$key]]) ? trim($row[$map[$key]]) : $default;
    }

    private function parseMoney($val) {
        $val = str_replace('R$', '', $val);
        $val = str_replace('.', '', $val); // Tira ponto de milhar
        $val = str_replace(',', '.', $val); // Troca vírgula decimal
        return (float) preg_replace('/[^0-9.]/', '', $val);
    }

    private function parseDate($val) {
        try {
            return Carbon::createFromFormat('d/m/Y', $val)->format('Y-m-d');
        } catch (\Exception $e) {
            return now()->format('Y-m-d');
        }
    }
}