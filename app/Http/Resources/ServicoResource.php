<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'codigo'                  => $this->codigo,
            'descricao'               => $this->descricao,
            'descricao_detalhada'     => $this->descricao_detalhada,
            'valor_unitario'          => (float) $this->valor_unitario,
            'cnae'                    => $this->cnae,
            'codigo_servico_municipal'=> $this->codigo_servico_municipal,
            'aliquota_iss'            => $this->aliquota_iss !== null ? (float) $this->aliquota_iss : null,
            'tipo_servico'            => $this->tipo_servico,
            'ativo'                   => (bool) $this->ativo,
            'observacoes'             => $this->observacoes,
            'created_at'              => optional($this->created_at)->toIso8601String(),
            'updated_at'              => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
