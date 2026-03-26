<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Titulo;
use App\Services\Bancos\ItauService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FaturamentoController extends Controller
{
    public function gerarBoleto($id, Request $request, ItauService $bancoService)
    {
        try {
            $fatura = Fatura::with(['cliente', 'titulos.cliente'])->findOrFail($id);

            /** @var Titulo|null $titulo */
            $titulo = $fatura->titulos->firstWhere('tipo', 'receber');

            if (!$titulo) {
                $titulo = $fatura->gerarTituloPadrao();

                if (!$titulo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Não foi possível gerar um título financeiro para esta fatura.',
                    ], 422);
                }

                $titulo->load('cliente');
            } else {
                $titulo->loadMissing('cliente');
            }

            if (!$titulo->cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'O título da fatura não possui cliente vinculado para registrar boleto.',
                ], 422);
            }

            if (!empty($titulo->nosso_numero)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Boleto já registrado anteriormente para esta fatura.',
                    'mode' => 'existing',
                    'data' => $this->serializeTituloBoleto($titulo),
                ]);
            }

            $mode = 'banco';

            try {
                $dadosBancarios = $bancoService->registrarBoleto($titulo);
            } catch (\Throwable $exception) {
                Log::warning('FATURAMENTO: falha ao registrar boleto no banco; aplicando fallback local.', [
                    'fatura_id' => $fatura->id,
                    'titulo_id' => $titulo->id,
                    'error' => $exception->getMessage(),
                ]);

                $mode = 'local';
                $dadosBancarios = [
                    'nosso_numero' => 'LOCAL' . str_pad((string) $titulo->id, 10, '0', STR_PAD_LEFT),
                    'linha_digitavel' => $titulo->linha_digitavel,
                    'url_boleto' => $titulo->url_boleto,
                ];
            }

            $payload = [
                'nosso_numero' => $dadosBancarios['nosso_numero'] ?? $titulo->nosso_numero,
                'linha_digitavel' => $dadosBancarios['linha_digitavel'] ?? $titulo->linha_digitavel,
                'url_boleto' => $dadosBancarios['url_boleto'] ?? $titulo->url_boleto,
                'status' => $titulo->status === 'pago' ? 'pago' : 'aberto',
            ];

            if (Schema::hasColumn('titulos', 'codigo_barras')) {
                $payload['codigo_barras'] = $dadosBancarios['codigo_barras'] ?? $titulo->codigo_barras;
            }

            $titulo->update($payload);

            if (in_array($fatura->status, ['emitida', 'nfse_emitida', 'aguardando_boleto'], true)) {
                $fatura->update(['status' => 'concluida']);
            }

            return response()->json([
                'success' => true,
                'message' => $mode === 'banco'
                    ? 'Boleto registrado com sucesso no banco.'
                    : 'Boleto preparado em modo local. Homologue a integração bancária para emissão oficial.',
                'mode' => $mode,
                'data' => $this->serializeTituloBoleto($titulo->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar boleto: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function serializeTituloBoleto(Titulo $titulo): array
    {
        return [
            'titulo_id' => $titulo->id,
            'nosso_numero' => $titulo->nosso_numero,
            'codigo_barras' => Schema::hasColumn('titulos', 'codigo_barras') ? $titulo->codigo_barras : null,
            'linha_digitavel' => $titulo->linha_digitavel,
            'url_boleto' => $titulo->url_boleto,
            'status' => $titulo->status,
        ];
    }
}
