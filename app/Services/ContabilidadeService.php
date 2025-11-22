<?php

namespace App\Services\Financeiro;

use App\Models\LancamentoContabil;
use App\Models\Nfse;
use App\Models\PlanoConta;

class ContabilidadeService
{
    /**
     * Gera o lançamento contábil automático de uma NFS-e emitida
     */
    public function contabilizarNotaFiscal(Nfse $nfse)
    {
        // Exemplo de regra contábil:
        // D - Clientes a Receber (Ativo Circulante)
        // C - Receita de Serviços (Resultado)
        
        // Buscar contas padrão (Em produção, isso vem de configuração)
        $contaClientes = PlanoConta::where('codigo', '1.1.02.01')->first() ?? PlanoConta::first(); 
        $contaReceita  = PlanoConta::where('codigo', '3.1.01.01')->first() ?? PlanoConta::first();

        if (!$contaClientes || !$contaReceita) return; // Evita erro se não tiver plano de contas

        LancamentoContabil::create([
            'data_lancamento' => $nfse->data_emissao ?? now(),
            'historico'       => "Vlr. Ref. NFS-e {$nfse->numero_nfse} - " . ($nfse->cliente->razao_social ?? 'Consumidor'),
            'conta_debito_id' => $contaClientes->id,
            'conta_credito_id'=> $contaReceita->id,
            'valor'           => $nfse->valor_total,
            'origem_tipo'     => Nfse::class,
            'origem_id'       => $nfse->id,
            'centro_custo_id' => null // Poderia vir da fatura
        ]);

        // Se houver retenções, geraria lançamentos adicionais aqui (D - Despesa Tributária / C - Impostos a Recolher)
    }
}