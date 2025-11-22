<?php

namespace App\Services\Financeiro;

use App\Models\Titulo;
use App\Models\PlanoConta;
use App\Models\LancamentoContabil;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ContabilidadeInteligenteService
{
    /**
     * Analisa um título financeiro e gera o lançamento contábil (Partida Dobrada)
     * Baseado nas regras do Manual Domínio (Lançamentos Padrão) mas automático.
     */
    public function gerarLancamentoAutomatico(Titulo $titulo)
    {
        // 1. Identificar Contas (Débito e Crédito)
        $contas = $this->classificarContas($titulo);

        if (!$contas['debito'] || !$contas['credito']) {
            // Se não souber classificar, joga para "Contas Transitórias" (Manual Sebrae - Ajustes)
            // E marca para auditoria do contador
            return null; 
        }

        // 2. Criar o Lançamento
        $lancamento = LancamentoContabil::create([
            'data_lancamento' => $titulo->data_pagamento ?? $titulo->data_emissao,
            'conta_debito_id' => $contas['debito'],
            'conta_credito_id'=> $contas['credito'],
            'valor'           => $titulo->valor_original,
            'historico'       => $this->gerarHistoricoInteligente($titulo),
            'origem_tipo'     => Titulo::class,
            'origem_id'       => $titulo->id,
            'centro_custo_id' => $titulo->centro_custo_id
        ]);

        // 3. Aprender com essa decisão (Reforço de IA)
        $this->aprenderPadrao($titulo->descricao, $contas['debito']);

        return $lancamento;
    }

    /**
     * A lógica "IA" que substitui a configuração manual
     */
    private function classificarContas(Titulo $titulo)
    {
        $debito = null;
        $credito = null;

        // --- LÓGICA PARA CRÉDITO (Origem do Recurso) ---
        if ($titulo->tipo === 'pagar') {
            // Se é pagar, sai do Banco ou Caixa
            // Tenta achar a conta "Banco" ou "Caixa" no plano
            $credito = PlanoConta::where('codigo', 'like', '1.1.01%') // Disponibilidades
                                 ->where('analitica', true)
                                 ->first()->id ?? null;
        } else {
            // Se é receber, entra Receita (Resultado)
            // A conta de crédito será a Receita de Serviços
            $credito = PlanoConta::where('codigo', 'like', '3.01%') // Receitas
                                 ->where('analitica', true)
                                 ->first()->id ?? null;
        }

        // --- LÓGICA PARA DÉBITO (Aplicação do Recurso) ---
        // Aqui entra a IA: Analisar a descrição para achar a despesa correta
        
        // 1. Busca exata na tabela de aprendizado
        $regraAprendida = DB::table('inteligencia_contabil_regras')
            ->where('termo_origem', $titulo->descricao)
            ->orderByDesc('confianca')
            ->first();

        if ($regraAprendida) {
            $debito = $regraAprendida->conta_sugerida_id;
        } else {
            // 2. Busca por palavras-chave no Plano de Contas (Full Text Search simples)
            $palavras = explode(' ', Str::lower($titulo->descricao));
            foreach ($palavras as $palavra) {
                if (strlen($palavra) < 3) continue; // Ignora "de", "em", "o"
                
                $contaCandidata = PlanoConta::whereJsonContains('palavras_chave', $palavra)->first();
                if ($contaCandidata) {
                    $debito = $contaCandidata->id;
                    break;
                }
            }
        }

        // Fallback: Se não achou pelo nome, usa a categoria que o usuário selecionou na criação do título
        if (!$debito && $titulo->plano_conta_id) {
            $debito = $titulo->plano_conta_id;
        }

        // Inverte lógica se for Recebimento (D=Banco, C=Receita)
        if ($titulo->tipo === 'receber') {
            $temp = $debito;
            $debito = $credito; // Banco (que estava como credito no pagar) vira débito
            $credito = $temp;   // Receita vira crédito
        }

        return ['debito' => $debito, 'credito' => $credito];
    }

    private function gerarHistoricoInteligente(Titulo $titulo)
    {
        // Gera um histórico padrão contábil (ex: "Vlr. ref. pgto NF 123...")
        $prefixo = $titulo->tipo === 'pagar' ? 'Pgto' : 'Recbt';
        return "$prefixo ref. {$titulo->descricao} - Docto: {$titulo->nosso_numero}";
    }

    private function aprenderPadrao($termo, $contaId)
    {
        if (!$contaId) return;
        
        // Incrementa confiança se já existe, ou cria novo
        $regra = DB::table('inteligencia_contabil_regras')
            ->where('termo_origem', $termo)
            ->where('conta_sugerida_id', $contaId)
            ->first();

        if ($regra) {
            DB::table('inteligencia_contabil_regras')
                ->where('id', $regra->id)
                ->increment('confianca');
        } else {
            DB::table('inteligencia_contabil_regras')->insert([
                'termo_origem' => $termo,
                'conta_sugerida_id' => $contaId,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}