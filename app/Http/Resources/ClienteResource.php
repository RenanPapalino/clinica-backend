<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'cnpj_formatado' => $this->cnpj_formatado,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'inscricao_municipal' => $this->inscricao_municipal,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'celular' => $this->celular,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'endereco_completo' => $this->endereco_completo,
            'status' => $this->status,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
