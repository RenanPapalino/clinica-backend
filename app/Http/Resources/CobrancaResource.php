<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CobrancaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'cliente_id'    => $this->cliente_id,
            'cliente'       => new ClienteResource($this->whenLoaded('cliente')),
            'fatura_id'     => $this->fatura_id,
            'titulo_id'     => $this->titulo_id,
            'meio'          => $this->meio,
            'status'        => $this->status,
            'canal'         => $this->canal,
            'descricao'     => $this->descricao,
            'data_envio'    => optional($this->data_envio)->toIso8601String(),
            'data_pagamento'=> optional($this->data_pagamento)->toIso8601String(),
            'valor_cobrado' => $this->valor_cobrado !== null ? (float) $this->valor_cobrado : null,
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
