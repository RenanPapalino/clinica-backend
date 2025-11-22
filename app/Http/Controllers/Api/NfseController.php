<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nfse;
use App\Models\Fatura;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
}
