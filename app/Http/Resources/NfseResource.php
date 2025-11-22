<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NfseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'fatura_id'          => $this->fatura_id,
            'cliente_id'         => $this->cliente_id,
            'cliente'            => new ClienteResource($this->whenLoaded('cliente')),
            'lote_id'            => $this->lote_id,
            'numero_nfse'        => $this->numero_nfse,
            'codigo_verificacao' => $this->codigo_verificacao,
            'protocolo'          => $this->protocolo,
            'data_emissao'       => optional($this->data_emissao)->toIso8601String(),
            'data_envio'         => optional($this->data_envio)->toIso8601String(),
            'data_autorizacao'   => optional($this->data_autorizacao)->toIso8601String(),
            'valor_servicos'     => $this->valor_servicos !== null ? (float) $this->valor_servicos : null,
            'valor_deducoes'     => $this->valor_deducoes !== null ? (float) $this->valor_deducoes : null,
            'valor_iss'          => $this->valor_iss !== null ? (float) $this->valor_iss : null,
            'aliquota_iss'       => $this->aliquota_iss !== null ? (float) $this->aliquota_iss : null,
            'valor_liquido'      => $this->valor_liquido !== null ? (float) $this->valor_liquido : null,
            'status'             => $this->status,
            'codigo_servico'     => $this->codigo_servico,
            'discriminacao'      => $this->discriminacao,
            'xml_nfse'           => $this->xml_nfse,
            'pdf_url'            => $this->pdf_url,
            'mensagem_erro'      => $this->mensagem_erro,
            'detalhes_erro'      => $this->detalhes_erro,
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
