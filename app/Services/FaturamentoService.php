<?php

namespace App\Services;

use App\Models\OrdemServico;
use App\Models\Fatura;
use App\Models\FaturaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class FaturamentoService
{
    public function gerarFaturaDeOS($osId)
    {
        DB::beginTransaction();
        try {
            // 1. Carregar Dados Completos
            $os = OrdemServico::with(['itens', 'cliente', 'rateios'])->findOrFail($osId);

            if ($os->status !== 'pendente') {
                throw new Exception("Esta OS já foi faturada ou cancelada.");
            }

            $cliente = $os->cliente;
            if (!$cliente) throw new Exception("OS sem cliente vinculado.");

            // 2. Definição de Parâmetros Fiscais (Pode vir de config ou tabela parametros)
            // Lógica: Se cliente for de fora do município, retém ISS? Depende da regra da empresa.
            // Aqui aplicamos uma regra padrão, você pode ajustar as alíquotas.
            $aliquotaIss = $cliente->reter_iss ? 0 : 2.00; // Exemplo: 2% se não retido
            $valorIss = ($os->valor_total * $aliquotaIss) / 100;
            
            // Exemplo de retenções federais (se valor > 215, ex: 4.65% PCC)
            $valorRetencoes = 0; 
            if ($os->valor_total > 215) {
                // $valorRetencoes = ($os->valor_total * 0.0465); 
            }

            $valorLiquido = $os->valor_total - $valorRetencoes; // ISS geralmente não deduz do líquido a receber, salvo se retido.

            $iss = $os->cliente->reter_iss ? 0 : ($os->valor_total * 0.02); // Ex: 2%
            $liquido = $os->valor_total;

            // 3. Criar a Fatura (Cabeçalho Fiscal) respeitando colunas existentes na tabela
            $faturaData = [
                'cliente_id'       => $os->cliente_id,
                'numero_fatura'    => date('Y') . str_pad($os->id, 6, '0', STR_PAD_LEFT),
                'data_emissao'     => now(),
                'data_vencimento'  => now()->addDays(15),
            ];

            $colunasFaturas = array_flip(Schema::getColumnListing('faturas'));

            // Mapeia valores conforme as colunas disponíveis
            if (isset($colunasFaturas['valor_servicos'])) $faturaData['valor_servicos'] = $os->valor_total;
            if (isset($colunasFaturas['valor_descontos'])) $faturaData['valor_descontos'] = 0;
            if (isset($colunasFaturas['valor_acrescimos'])) $faturaData['valor_acrescimos'] = 0;
            if (isset($colunasFaturas['valor_iss'])) $faturaData['valor_iss'] = $iss;
            if (isset($colunasFaturas['valor_total'])) $faturaData['valor_total'] = $os->valor_total;

            // Colunas alternativas usadas em outros esquemas
            if (isset($colunasFaturas['valor_bruto'])) $faturaData['valor_bruto'] = $os->valor_total;
            if (isset($colunasFaturas['valor_liquido'])) $faturaData['valor_liquido'] = $liquido;
            if (isset($colunasFaturas['valor_retencoes'])) $faturaData['valor_retencoes'] = $valorRetencoes;
            if (isset($colunasFaturas['periodo_referencia'])) $faturaData['periodo_referencia'] = $os->competencia ?? now()->format('m/Y');

            if (isset($colunasFaturas['observacoes'])) $faturaData['observacoes'] = "Ref. OS #{$os->codigo_os}";
            // Não define status para evitar truncamento em enums divergentes; deixa o default do banco

            $fatura = Fatura::create($faturaData);

            // 4. Copiar Itens da OS para Fatura
            foreach ($os->itens as $item) {
                FaturaItem::create([
                    'fatura_id' => $fatura->id,
                    'servico_id' => $item->servico_id ?? null,
                    'descricao' => $item->descricao,
                    'quantidade' => $item->quantidade,
                    'valor_unitario' => $item->valor_unitario,
                    'valor_total' => $item->valor_total,
                ]);
            }

            // 5. Gerar Título Financeiro (Contas a Receber)
            $fatura->loadMissing('cliente');
            $fatura->gerarTituloPadrao();

            // 6. Atualizar Status da OS
            $os->status = 'faturada';
            $os->save();

            DB::commit();

            return $fatura;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function gerarNumeroFatura()
    {
        // Exemplo simples: Ano + ID sequencial. Ideal usar uma sequence no banco.
        $lastId = Fatura::max('id') ?? 0;
        return date('Y') . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);
    }
}
