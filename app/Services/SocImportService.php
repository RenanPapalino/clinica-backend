<?php

namespace App\Services\Integracao;

use App\Models\Cliente;
use App\Services\Financeiro\TributoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use App\Services\Integracao\SocIntegrationService; // Importar

class SocImportService
{
    protected $tributoService;

public function sincronizarSoc(Request $request, SocIntegrationService $socService)
    {
        // Em produção, esses dados viriam do .env ou de uma tabela de configuração
        $request->validate([
            'empresa_id' => 'required|string', // Ex: 957
            'codigo_soc' => 'required|string', // A senha/hash do SOC
        ]);

        try {
            $stats = $socService->sincronizarClientes(
                $request->empresa_id,
                $request->codigo_soc
            );

            return response()->json([
                'success' => true,
                'message' => "Sincronização concluída: {$stats['criados']} criados, {$stats['atualizados']} atualizados.",
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Falha na sincronização: ' . $e->getMessage()
            ], 500);
        }
    }

    public function __construct(TributoService $tributoService)
    {
        $this->tributoService = $tributoService;
    }

    /**
     * Analisa o arquivo e retorna uma prévia do que será gerado
     */
    public function analisarArquivo(array $linhasCsv): array
    {
        $faturasDetectadas = [];
        $erros = [];
        
        // Estrutura temporária para agrupar itens por "Empresa Cliente" (Coluna E do seu CSV)
        $agrupamento = [];

        foreach ($linhasCsv as $index => $linha) {
            // Pula cabeçalhos inúteis ou linhas vazias
            if (count($linha) < 5 || empty($linha[4])) continue;

            // Detecção Inteligente: Tenta achar a coluna "Empresa Cliente" e "Total R$"
            // Baseado no seu CSV, a empresa cliente está no índice 4 (Coluna E)
            // O valor total está no índice 9 (Coluna J)
            
            $nomeCliente = trim($linha[4]);
            $descricaoServico = trim($linha[0] ?? 'Serviço SOC');
            $valorTotal = $this->parseMoney($linha[9] ?? '0');

            // Ignora linhas de cabeçalho repetidas
            if ($nomeCliente === 'Empresa Cliente' || $nomeCliente === '') continue;

            if (!isset($agrupamento[$nomeCliente])) {
                $agrupamento[$nomeCliente] = [
                    'cliente_nome' => $nomeCliente,
                    'itens' => [],
                    'valor_total_lote' => 0
                ];
            }

            $agrupamento[$nomeCliente]['itens'][] = [
                'descricao' => $descricaoServico,
                'unidade' => $linha[5] ?? '', // Coluna F
                'vidas' => $linha[7] ?? 0,    // Coluna H
                'valor' => $valorTotal,
                'data_evento' => $linha[6] ?? now()->format('Y-m-d'), // Data Cobrança
            ];
            
            $agrupamento[$nomeCliente]['valor_total_lote'] += $valorTotal;
        }

        // Validação: Verificar se os clientes existem no banco
        $resultado = [
            'validos' => [],
            'com_pendencia' => []
        ];

        foreach ($agrupamento as $nomeCliente => $dados) {
            // Tenta achar cliente por nome aproximado (LIKE) ou cria um hash temporário
            $cliente = Cliente::where('razao_social', 'like', "%{$nomeCliente}%")
                              ->orWhere('nome_fantasia', 'like', "%{$nomeCliente}%")
                              ->first();

            $faturaTemp = [
                'temp_id' => Str::uuid(),
                'cliente_nome_csv' => $nomeCliente,
                'cliente_id' => $cliente ? $cliente->id : null,
                'cliente_encontrado' => $cliente ? $cliente->razao_social : null,
                'cnpj' => $cliente ? $cliente->cnpj : null,
                'valor_total' => $dados['valor_total_lote'],
                'itens_count' => count($dados['itens']),
                'itens' => $dados['itens'],
                'erros' => []
            ];

            if (!$cliente) {
                $faturaTemp['erros'][] = "Cliente '{$nomeCliente}' não encontrado no cadastro.";
                $resultado['com_pendencia'][] = $faturaTemp;
            } else {
                // Simula cálculo de impostos para mostrar ao usuário
                $impostos = $this->tributoService->calcularRetencoes($dados['valor_total_lote'], $cliente);
                $faturaTemp['previsao_impostos'] = $impostos['total_retido'];
                $faturaTemp['valor_liquido'] = $impostos['valor_liquido'];
                
                $resultado['validos'][] = $faturaTemp;
            }
        }

        return $resultado;
    }

    private function parseMoney($valor)
    {
        if (is_numeric($valor)) return (float) $valor;
        // Remove R$, troca vírgula por ponto se necessário (formato brasileiro)
        // No seu CSV está "12067,89" (com aspas e vírgula)
        $limpo = str_replace(['R$', '.', ' '], '', $valor);
        $limpo = str_replace(',', '.', $limpo);
        return (float) $limpo;
    }
}