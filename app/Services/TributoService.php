<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Fornecedor;

class TributoService
{
    /**
     * Calcula os impostos retidos e o valor líquido de um título/fatura
     * * @param float $valorBruto
     * @param Cliente|Fornecedor $entidade
     * @return array
     */
    public function calcularRetencoes(float $valorBruto, $entidade): array
    {
        $retencoes = [
            'iss' => 0.00,
            'ir'  => 0.00,
            'pcc' => 0.00, // PIS + COFINS + CSLL
            'inss'=> 0.00,
            'total_retido' => 0.00,
            'valor_liquido' => $valorBruto
        ];

        if (!$entidade) {
            return $retencoes;
        }

        // Regra de negócio: Valor mínimo para retenção (Exemplo: R$ 10,00 para PCC)
        // Em um sistema real, esses limites viriam de uma tabela de configuração
        $minimoParaPcc = 215.05; 

        // 1. ISS (Imposto Sobre Serviços)
        if ($entidade->reter_iss && $entidade->aliquota_iss > 0) {
            $retencoes['iss'] = round($valorBruto * ($entidade->aliquota_iss / 100), 2);
        }

        // 2. IR (Imposto de Renda - Padrão 1.5% para serviços gerais, ajustável)
        if ($entidade->reter_ir) {
            $aliquotaIr = 1.5; // Poderia vir de config
            $retencoes['ir'] = round($valorBruto * ($aliquotaIr / 100), 2);
        }

        // 3. PCC (PIS/COFINS/CSLL - Padrão 4.65%)
        if ($entidade->reter_pcc && $valorBruto > $minimoParaPcc) {
            $aliquotaPcc = 4.65;
            $retencoes['pcc'] = round($valorBruto * ($aliquotaPcc / 100), 2);
        }

        // 4. INSS (Padrão 11% com teto, simplificado aqui)
        if ($entidade->reter_inss) {
            $aliquotaInss = 11.0;
            $retencoes['inss'] = round($valorBruto * ($aliquotaInss / 100), 2);
        }

        // Totalização
        $retencoes['total_retido'] = $retencoes['iss'] + $retencoes['ir'] + $retencoes['pcc'] + $retencoes['inss'];
        $retencoes['valor_liquido'] = $valorBruto - $retencoes['total_retido'];

        return $retencoes;
    }
}
