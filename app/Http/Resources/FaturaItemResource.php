<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaturaItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'fatura_id'      => $this->fatura_id,
            'servico_id'     => $this->servico_id,
            'servico'        => new ServicoResource($this->whenLoaded('servico')),
            'item_numero'    => $this->item_numero,
            'descricao'      => $this->descricao,
            'quantidade'     => (int) $this->quantidade,
            'valor_unitario' => (float) $this->valor_unitario,
            'valor_total'    => (float) $this->valor_total,
            'data_realizacao'=> optional($this->data_realizacao)->format('Y-m-d'),
            'funcionario'    => $this->funcionario,
            'matricula'      => $this->matricula,
        ];
    }
}
