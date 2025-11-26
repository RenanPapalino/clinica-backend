<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClienteImportService
{
    public function processarLote(array $clientesValidar)
    {
        $resultados = [
            'sucesso' => 0,
            'erros' => 0,
            'detalhes' => []
        ];

        DB::beginTransaction();
        try {
            foreach ($clientesValidar as $dados) {
                // Normaliza CNPJ
                $cnpj = preg_replace('/\D/', '', $dados['cnpj'] ?? '');
                
                if (strlen($cnpj) !== 14 && strlen($cnpj) !== 11) {
                    $resultados['erros']++;
                    $resultados['detalhes'][] = "CNPJ/CPF inválido: {$dados['cnpj']} ({$dados['razao_social']})";
                    continue;
                }

                // Verifica duplicidade
                $cliente = Cliente::where('cnpj', $cnpj)->first();

                $campos = [
                    'razao_social' => mb_strtoupper($dados['razao_social']),
                    'nome_fantasia' => mb_strtoupper($dados['nome_fantasia'] ?? $dados['razao_social']),
                    'email' => strtolower($dados['email'] ?? ''),
                    'telefone' => $dados['telefone'] ?? null,
                    'celular' => $dados['celular'] ?? null,
                    'cep' => preg_replace('/\D/', '', $dados['cep'] ?? ''),
                    'logradouro' => $dados['logradouro'] ?? null,
                    'numero' => $dados['numero'] ?? 'S/N',
                    'bairro' => $dados['bairro'] ?? null,
                    'cidade' => $dados['cidade'] ?? null,
                    'uf' => strtoupper($dados['uf'] ?? ''),
                    'status' => 'ativo'
                ];

                if ($cliente) {
                    $cliente->update($campos);
                } else {
                    Cliente::create(array_merge($campos, ['cnpj' => $cnpj]));
                }
                $resultados['sucesso']++;
            }
            
            DB::commit();
            return $resultados;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}