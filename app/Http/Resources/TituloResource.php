<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TituloResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'cliente_id'      => $this->cliente_id,
            'cliente'         => new ClienteResource($this->whenLoaded('cliente')),
            'fatura_id'       => $this->fatura_id,
            'numero_titulo'   => $this->numero_titulo,
            'nosso_numero'    => $this->nosso_numero,
            'data_emissao'    => optional($this->data_emissao)->format('Y-m-d'),
            'data_vencimento' => optional($this->data_vencimento)->format('Y-m-d'),
            'data_pagamento'  => optional($this->data_pagamento)->format('Y-m-d'),
            'valor_original'  => (float) $this->valor_original,
            'valor_juros'     => (float) $this->valor_juros,
            'valor_multa'     => (float) $this->valor_multa,
            'valor_desconto'  => (float) $this->valor_desconto,
            'valor_pago'      => (float) $this->valor_pago,
            'valor_saldo'     => (float) $this->valor_saldo,
            'status'          => $this->status,
            'forma_pagamento' => $this->forma_pagamento,
            'codigo_barras'   => $this->codigo_barras,
            'linha_digitavel' => $this->linha_digitavel,
            'url_boleto'      => $this->url_boleto,
            'observacoes'     => $this->observacoes,
            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
