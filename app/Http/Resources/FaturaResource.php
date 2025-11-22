<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'numero_fatura'      => $this->numero_fatura,
            'cliente_id'         => $this->cliente_id,
            'cliente'            => new ClienteResource($this->whenLoaded('cliente')),
            'data_emissao'       => optional($this->data_emissao)->format('Y-m-d'),
            'data_vencimento'    => optional($this->data_vencimento)->format('Y-m-d'),
            'periodo_referencia' => $this->periodo_referencia,
            'valor_servicos'     => (float) $this->valor_servicos,
            'valor_descontos'    => (float) $this->valor_descontos,
            'valor_acrescimos'   => (float) $this->valor_acrescimos,
            'valor_iss'          => (float) $this->valor_iss,
            'valor_total'        => (float) $this->valor_total,
            'status'             => $this->status,
            'nfse_emitida'       => (bool) $this->nfse_emitida,
            'observacoes'        => $this->observacoes,
            'itens'              => FaturaItemResource::collection($this->whenLoaded('itens')),
            'titulos'            => TituloResource::collection($this->whenLoaded('titulos')),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
