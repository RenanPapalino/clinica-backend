<?php

// ADICIONAR ESTE MÉTODO AO FaturaController.php

/**
 * Emitir NFSe para uma fatura específica
 * Adicionar ao: app/Http/Controllers/Api/FaturaController.php
 */
public function emitirNfse($id)
{
    try {
        $fatura = Fatura::with(['cliente', 'itens'])->find($id);

        if (!$fatura) {
            return response()->json([
                'success' => false,
                'message' => 'Fatura não encontrada',
            ], 404);
        }

        if ($fatura->nfse_numero) {
            return response()->json([
                'success' => false,
                'message' => 'Fatura já possui NFSe emitida',
            ], 400);
        }

        DB::beginTransaction();

        // Aqui você integraria com a API da prefeitura
        // Por enquanto vamos simular a emissão
        $numeroNfse = 'NFSe-' . date('Ymd') . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);

        $fatura->update([
            'nfse_numero' => $numeroNfse,
            'nfse_status' => 'emitida',
            'nfse_data_emissao' => now(),
        ]);

        // Criar registro na tabela nfse (se existir)
        \App\Models\Nfse::create([
            'fatura_id' => $fatura->id,
            'numero' => $numeroNfse,
            'status' => 'emitida',
            'data_emissao' => now(),
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'NFSe emitida com sucesso',
            'data' => [
                'fatura_id' => $fatura->id,
                'numero_fatura' => $fatura->numero_fatura,
                'nfse_numero' => $numeroNfse,
                'nfse_status' => 'emitida',
            ],
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Erro ao emitir NFSe: ' . $e->getMessage(),
        ], 500);
    }
}
