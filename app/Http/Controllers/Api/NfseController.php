<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nfse;
use App\Models\Fatura;
use App\Http\Resources\NfseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NfseController extends Controller
{
    /**
     * Lista NFSe (com filtros)
     * GET /nfse
     */
    public function index(Request $request)
    {
        $query = Nfse::with(['fatura', 'cliente'])
            ->orderByDesc('data_emissao')
            ->orderByDesc('id');

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        if ($request->filled('fatura_id')) {
            $query->where('fatura_id', $request->fatura_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = (int) $request->get('per_page', 20);

        $nfse = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $nfse,
        ]);
    }

    /**
     * Cria uma NFSe manualmente (ex.: correção ou registro externo).
     * POST /nfse
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'fatura_id'        => ['required', 'integer', 'exists:faturas,id'],
            'cliente_id'       => ['nullable', 'integer', 'exists:clientes,id'],
            'numero_nfse'      => ['nullable', 'string', 'max:100'],
            'codigo_verificacao' => ['nullable', 'string', 'max:100'],
            'protocolo'        => ['nullable', 'string', 'max:100'],
            'data_emissao'     => ['nullable', 'date'],
            'data_envio'       => ['nullable', 'date'],
            'data_autorizacao' => ['nullable', 'date'],
            'valor_servicos'   => ['nullable', 'numeric'],
            'valor_deducoes'   => ['nullable', 'numeric'],
            'valor_iss'        => ['nullable', 'numeric'],
            'aliquota_iss'     => ['nullable', 'numeric'],
            'valor_liquido'    => ['nullable', 'numeric'],
            'status'           => ['nullable', 'string', 'in:pendente,emitida,cancelada,erro'],
            'codigo_servico'   => ['nullable', 'string', 'max:50'],
            'discriminacao'    => ['nullable', 'string'],
            'xml_nfse'         => ['nullable'],
            'pdf_url'          => ['nullable', 'string'],
            'mensagem_erro'    => ['nullable', 'string'],
            'detalhes_erro'    => ['nullable', 'array'],
        ]);

        $fatura = Fatura::with('cliente')->findOrFail($data['fatura_id']);

        $valorServicos = $data['valor_servicos'] ?? $fatura->valor_servicos ?? $fatura->valor_total ?? 0;
        $aliquotaIss   = $data['aliquota_iss'] ?? ($fatura->cliente->aliquota_iss ?? null);
        $valorIss      = $data['valor_iss'] ?? ($aliquotaIss !== null ? round($valorServicos * ($aliquotaIss / 100), 2) : null);
        $valorLiquido  = $data['valor_liquido'] ?? ($valorServicos - ($valorIss ?? 0));

        $nfse = Nfse::create([
            'fatura_id'        => $data['fatura_id'],
            'cliente_id'       => $data['cliente_id'] ?? $fatura->cliente_id,
            'lote_id'          => null,
            'numero_nfse'      => $data['numero_nfse'] ?? null,
            'codigo_verificacao' => $data['codigo_verificacao'] ?? null,
            'protocolo'        => $data['protocolo'] ?? ('MAN-' . Str::random(6)),
            'data_envio'       => isset($data['data_envio']) ? Carbon::parse($data['data_envio']) : now(),
            'data_emissao'     => isset($data['data_emissao']) ? Carbon::parse($data['data_emissao']) : now(),
            'data_autorizacao' => isset($data['data_autorizacao']) ? Carbon::parse($data['data_autorizacao']) : null,
            'valor_servicos'   => $valorServicos,
            'valor_deducoes'   => $data['valor_deducoes'] ?? 0,
            'valor_iss'        => $valorIss,
            'aliquota_iss'     => $aliquotaIss,
            'valor_liquido'    => $valorLiquido,
            'status'           => $data['status'] ?? 'pendente',
            'codigo_servico'   => $data['codigo_servico'] ?? null,
            'discriminacao'    => $data['discriminacao'] ?? ($fatura->observacoes ?? null),
            'xml_nfse'         => $data['xml_nfse'] ?? null,
            'pdf_url'          => $data['pdf_url'] ?? null,
            'mensagem_erro'    => $data['mensagem_erro'] ?? null,
            'detalhes_erro'    => $data['detalhes_erro'] ?? null,
        ]);

        if ($nfse->status === 'emitida') {
            $fatura->update([
                'nfse_emitida' => true,
                'nfse_numero'  => $nfse->numero_nfse ?? $nfse->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new NfseResource($nfse->load(['fatura', 'cliente'])),
        ], 201);
    }

    /**
     * Detalha uma NFSe
     * GET /nfse/{id}
     */
    public function show($id)
    {
        $nfse = Nfse::with(['fatura', 'cliente'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new NfseResource($nfse),
        ]);
    }

    /**
     * Atualiza uma NFSe (correção manual)
     * PUT /nfse/{id}
     */
    public function update(Request $request, $id)
    {
        $nfse = Nfse::with(['fatura', 'cliente'])->findOrFail($id);

        $data = $request->validate([
            'numero_nfse'      => ['nullable', 'string', 'max:100'],
            'codigo_verificacao' => ['nullable', 'string', 'max:100'],
            'protocolo'        => ['nullable', 'string', 'max:100'],
            'data_emissao'     => ['nullable', 'date'],
            'data_envio'       => ['nullable', 'date'],
            'data_autorizacao' => ['nullable', 'date'],
            'valor_servicos'   => ['nullable', 'numeric'],
            'valor_deducoes'   => ['nullable', 'numeric'],
            'valor_iss'        => ['nullable', 'numeric'],
            'aliquota_iss'     => ['nullable', 'numeric'],
            'valor_liquido'    => ['nullable', 'numeric'],
            'status'           => ['nullable', 'string', 'in:pendente,emitida,cancelada,erro'],
            'codigo_servico'   => ['nullable', 'string', 'max:50'],
            'discriminacao'    => ['nullable', 'string'],
            'xml_nfse'         => ['nullable'],
            'pdf_url'          => ['nullable', 'string'],
            'mensagem_erro'    => ['nullable', 'string'],
            'detalhes_erro'    => ['nullable', 'array'],
        ]);

        $valorServicos = $data['valor_servicos'] ?? $nfse->valor_servicos;
        $aliquotaIss   = $data['aliquota_iss'] ?? $nfse->aliquota_iss;
        $valorIss      = array_key_exists('valor_iss', $data)
            ? $data['valor_iss']
            : ($aliquotaIss !== null ? round($valorServicos * ($aliquotaIss / 100), 2) : $nfse->valor_iss);
        $valorLiquido  = $data['valor_liquido'] ?? ($valorServicos - ($valorIss ?? 0));

        $nfse->update([
            'numero_nfse'      => $data['numero_nfse'] ?? $nfse->numero_nfse,
            'codigo_verificacao' => $data['codigo_verificacao'] ?? $nfse->codigo_verificacao,
            'protocolo'        => $data['protocolo'] ?? $nfse->protocolo,
            'data_envio'       => isset($data['data_envio']) ? Carbon::parse($data['data_envio']) : $nfse->data_envio,
            'data_emissao'     => isset($data['data_emissao']) ? Carbon::parse($data['data_emissao']) : $nfse->data_emissao,
            'data_autorizacao' => isset($data['data_autorizacao']) ? Carbon::parse($data['data_autorizacao']) : $nfse->data_autorizacao,
            'valor_servicos'   => $valorServicos,
            'valor_deducoes'   => $data['valor_deducoes'] ?? $nfse->valor_deducoes,
            'valor_iss'        => $valorIss,
            'aliquota_iss'     => $aliquotaIss,
            'valor_liquido'    => $valorLiquido,
            'status'           => $data['status'] ?? $nfse->status,
            'codigo_servico'   => $data['codigo_servico'] ?? $nfse->codigo_servico,
            'discriminacao'    => $data['discriminacao'] ?? $nfse->discriminacao,
            'xml_nfse'         => array_key_exists('xml_nfse', $data) ? $data['xml_nfse'] : $nfse->xml_nfse,
            'pdf_url'          => array_key_exists('pdf_url', $data) ? $data['pdf_url'] : $nfse->pdf_url,
            'mensagem_erro'    => $data['mensagem_erro'] ?? $nfse->mensagem_erro,
            'detalhes_erro'    => $data['detalhes_erro'] ?? $nfse->detalhes_erro,
        ]);

        if (($data['status'] ?? $nfse->status) === 'emitida') {
            $nfse->fatura->update([
                'nfse_emitida' => true,
                'nfse_numero'  => $nfse->numero_nfse ?? $nfse->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new NfseResource($nfse),
        ]);
    }

    /**
     * Remove uma NFSe (uso interno)
     * DELETE /nfse/{id}
     */
    public function destroy($id)
    {
        $nfse = Nfse::findOrFail($id);
        $nfse->delete();

        if ($nfse->fatura) {
            $nfse->fatura->update([
                'nfse_emitida' => false,
                'nfse_numero'  => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'NFSe removida',
        ]);
    }

    /**
     * Emite NFSe em lote a partir de faturas
     * POST /nfse/emitir-lote
     */
    public function emitirLote(Request $request)
    {
        $data = $request->validate([
            'faturas' => ['required', 'array', 'min:1'],
            'faturas.*' => ['integer', 'exists:faturas,id'],
        ]);

        $protocolo = 'PROTO-' . now()->format('YmdHis') . '-' . Str::random(6);

        $criadas = [];

        try {
            DB::beginTransaction();

            foreach ($data['faturas'] as $faturaId) {
                $fatura = Fatura::with('cliente')->findOrFail($faturaId);

                // Se já existe nfse associada, pula ou atualiza
                if ($fatura->nfse_emitida) {
                    continue;
                }

                $nfse = Nfse::create([
                    'fatura_id'        => $fatura->id,
                    'cliente_id'       => $fatura->cliente_id,
                    'lote_id'          => null,
                    'numero_nfse'      => null, // preencher depois do retorno da prefeitura
                    'codigo_verificacao' => null,
                    'protocolo'        => $protocolo,
                    'data_envio'       => now(),
                    'data_emissao'     => null,
                    'data_autorizacao' => null,
                    'valor_servicos'   => $fatura->valor_servicos,
                    'valor_deducoes'   => 0,
                    'valor_iss'        => $fatura->valor_iss,
                    'aliquota_iss'     => null,
                    'valor_liquido'    => $fatura->valor_total,
                    'status'           => 'pendente',
                    'codigo_servico'   => null,
                    'discriminacao'    => null,
                    'xml_nfse'         => null,
                    'pdf_url'          => null,
                    'mensagem_erro'    => null,
                    'detalhes_erro'    => null,
                ]);

                $fatura->nfse_emitida = false; // ainda pendente
                $fatura->save();

                $criadas[] = $nfse;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'protocolo' => $protocolo,
                'mensagem' => 'Lote de NFSe enviado/registrado com sucesso (pendente de autorização).',
                'data' => $criadas,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao emitir lote de NFSe',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Consulta por protocolo (retorna NFSe associadas)
     * GET /nfse/consultar-protocolo?protocolo=...
     */
    public function consultarProtocolo(Request $request)
    {
        $request->validate([
            'protocolo' => ['required', 'string'],
        ]);

        $lista = Nfse::with(['fatura', 'cliente'])
            ->where('protocolo', $request->protocolo)
            ->get();

        if ($lista->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma NFSe encontrada para esse protocolo.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $lista,
        ]);
    }

    /**
     * Download do XML da NFSe (conteúdo armazenado na coluna xml_nfse).
     * GET /nfse/{id}/xml
     */
    public function downloadXml($id)
    {
        $nfse = Nfse::findOrFail($id);

        if (empty($nfse->xml_nfse)) {
            return response()->json([
                'success' => false,
                'message' => 'XML não disponível para esta NFSe.',
            ], 404);
        }

        $filename = 'nfse_' . ($nfse->numero_nfse ?? $nfse->id) . '.xml';

        return response($nfse->xml_nfse, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Download do PDF da NFSe (se armazenado em Storage ou URL).
     * GET /nfse/{id}/pdf
     */
    public function downloadPdf($id)
    {
        $nfse = Nfse::findOrFail($id);
        $path = $nfse->pdf_url;

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'PDF não disponível para esta NFSe.',
            ], 404);
        }

        // Se for URL externa, apenas redireciona
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return redirect()->away($path);
        }

        if (!Storage::exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Arquivo PDF não encontrado.',
            ], 404);
        }

        $filename = 'nfse_' . ($nfse->numero_nfse ?? $nfse->id) . '.pdf';
        return Storage::download($path, $filename);
    }

    /**
     * Cancela a NFSe localmente, registrando motivo.
     * POST /nfse/{id}/cancelar
     */
    public function cancelar(Request $request, $id)
    {
        $nfse = Nfse::findOrFail($id);

        $data = $request->validate([
            'motivo' => ['nullable', 'string'],
        ]);

        $nfse->update([
            'status'        => 'cancelada',
            'mensagem_erro' => $data['motivo'] ?? 'Cancelada manualmente',
        ]);

        if ($nfse->fatura) {
            $nfse->fatura->update([
                'nfse_emitida' => false,
                'nfse_numero'  => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'NFSe cancelada.',
            'data' => new NfseResource($nfse->fresh(['fatura', 'cliente'])),
        ]);
    }
}
