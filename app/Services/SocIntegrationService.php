<?php

namespace App\Services\Integracao;

use App\Models\Cliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocIntegrationService
{
    // URL base do SOC (pode ir para o .env)
    protected $baseUrl = 'https://ws1.soc.com.br/WebSoc/exportadados';

    /**
     * Sincroniza clientes do SOC para o MDGestão
     * @param string $empresaId ID da empresa no SOC (ex: 957)
     * @param string $codigoSeguranca Código/Token de acesso
     */
    public function sincronizarClientes(string $empresaId, string $codigoSeguranca)
    {
        // Monta o parâmetro JSON exigido pelo SOC
        // Formato: parametro={"empresa":"957","codigo":"SEU_CODIGO","tipo":"EMPRESA"}
        $parametro = json_encode([
            "empresa" => $empresaId,
            "codigo"  => $codigoSeguranca,
            "tipo"    => "EMPRESA" // Busca o cadastro de empresas/clientes
        ]);

        try {
            $response = Http::get($this->baseUrl, [
                'parametro' => $parametro
            ]);

            if ($response->failed()) {
                throw new \Exception("Erro ao conectar no SOC: " . $response->status());
            }

            // O SOC retorna o JSON direto ou dentro de uma string, dependendo da config.
            // Assumindo que venha direto como no seu arquivo txt.
            $dados = $response->json();

            if (!is_array($dados)) {
                throw new \Exception("Formato de resposta inválido do SOC.");
            }

            $stats = ['criados' => 0, 'atualizados' => 0, 'erros' => 0];

            foreach ($dados as $item) {
                $this->processarCliente($item, $stats);
            }

            return $stats;

        } catch (\Exception $e) {
            Log::error("Erro SOC Sync: " . $e->getMessage());
            throw $e;
        }
    }

    private function processarCliente($item, &$stats)
    {
        // Mapeamento dos campos do TXT
        $codigoSoc = $item['CODIGO'] ?? null;
        $cnpj = preg_replace('/\D/', '', $item['CNPJ'] ?? '');
        
        if (!$codigoSoc && !$cnpj) return;

        // Tenta encontrar pelo Código SOC ou pelo CNPJ
        $cliente = Cliente::where('codigo_soc', $codigoSoc)
            ->orWhere('cnpj', $cnpj)
            ->first();

        $dadosAtualizar = [
            'codigo_soc'    => $codigoSoc,
            'razao_social'  => $item['RAZAOSOCIAL'] ?? 'Sem Nome',
            'nome_fantasia' => $item['APELIDO(NOMEABREVIADO)'] ?? null,
            'cnpj'          => $cnpj,
            'inscricao_estadual' => $item['INSCRICAOESTADUAL'] ?? null,
            'inscricao_municipal' => $item['INSCRICAOMUNICIPAL'] ?? null,
            
            // Endereço
            'endereco' => $item['ENDERECO'] ?? null,
            'numero'   => $item['NUMEROENDERECO'] ?? null,
            'bairro'   => $item['BAIRRO'] ?? null,
            'cidade'   => $item['CIDADE'] ?? null,
            'uf'       => $item['UF'] ?? null,
            'cep'      => preg_replace('/\D/', '', $item['CEP'] ?? ''),
            
            // Status (1 = Ativo, 0 = Inativo)
            'status' => ($item['ATIVO'] ?? '1') == '1' ? 'ativo' : 'inativo',
            'ultima_sincronizacao_soc' => now(),
        ];

        if ($cliente) {
            $cliente->update($dadosAtualizar);
            $stats['atualizados']++;
        } else {
            Cliente::create($dadosAtualizar);
            $stats['criados']++;
        }
    }
}