<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'cnpj'               => $this->cnpj,
            'razao_social'       => $this->razao_social,
            'nome_fantasia'      => $this->nome_fantasia,
            'inscricao_municipal'=> $this->inscricao_municipal,
            'inscricao_estadual' => $this->inscricao_estadual,
            'email'              => $this->email,
            'telefone'           => $this->telefone,
            'celular'            => $this->celular,
            'site'               => $this->site,
            'cep'                => $this->cep,
            'logradouro'         => $this->logradouro,
            'numero'             => $this->numero,
            'complemento'        => $this->complemento,
            'bairro'             => $this->bairro,
            'cidade'             => $this->cidade,
            'uf'                 => $this->uf,
            'status'             => $this->status,
            'aliquota_iss'       => $this->aliquota_iss !== null ? (float) $this->aliquota_iss : null,
            'prazo_pagamento_dias'=> $this->prazo_pagamento_dias,
            'observacoes'        => $this->observacoes,
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
