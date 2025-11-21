<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\Cobranca;
use App\Models\Fatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CobrancaController extends Controller
{
    use ApiResponseTrait;

    /**
     * Listar todas as cobranças
     */
    public function index(Request $request)
    {
        $query = Cobranca::with(['fatura', 'fatura.cliente']);

        // Filtro por status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtro por data
        if ($request->has('data_inicio')) {
            $query->whereDate('data_envio', '>=', $request->data_inicio);
        }

        if ($request->has('data_fim')) {
            $query->whereDate('data_envio', '<=', $request->data_fim);
        }

        $cobrancas = $query->orderBy('created_at', 'desc')->paginate(15);

        return $this->paginatedResponse($cobrancas);
    }

    /**
     * Enviar cobrança para uma fatura
     */
    public function enviar(Request $request, $faturaId)
    {
        try {
            $fatura = Fatura::with('cliente')->find($faturaId);

            if (!$fatura) {
                return $this->errorResponse('Fatura não encontrada', 404);
            }

            if ($fatura->status === 'paga') {
                return $this->errorResponse('Fatura já está paga', 400);
            }

            $canal = $request->input('canal', 'email'); // email, whatsapp, sms
            $destinatario = $request->input('destinatario') ?? $fatura->cliente->email;

            DB::beginTransaction();

            // Criar registro de cobrança
            $cobranca = Cobranca::create([
                'fatura_id' => $fatura->id,
                'data_envio' => now(),
                'canal' => $canal,
                'destinatario' => $destinatario,
                'status' => 'enviada',
                'tentativas' => 1,
            ]);

            // Aqui você integraria com serviço de email/WhatsApp
            // Por enquanto apenas simula o envio
            $this->enviarCobrancaPorCanal($fatura, $canal, $destinatario);

            DB::commit();

            return $this->successResponse([
                'cobranca' => $cobranca,
                'fatura' => [
                    'id' => $fatura->id,
                    'numero_fatura' => $fatura->numero_fatura,
                    'valor_total' => $fatura->valor_total,
                    'data_vencimento' => $fatura->data_vencimento,
                ],
            ], 'Cobrança enviada com sucesso');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Erro ao enviar cobrança: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Listar cobranças vencidas
     */
    public function vencidos()
    {
        $vencidas = Fatura::with('cliente')
            ->where('status', 'pendente')
            ->whereDate('data_vencimento', '<', now())
            ->get();

        return $this->successResponse($vencidas);
    }

    /**
     * Histórico de cobranças de uma fatura
     */
    public function historico($faturaId)
    {
        $cobrancas = Cobranca::where('fatura_id', $faturaId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($cobrancas);
    }

    /**
     * Método auxiliar para enviar cobrança
     * (Integrar com serviços reais de email/WhatsApp)
     */
    private function enviarCobrancaPorCanal($fatura, $canal, $destinatario)
    {
        // TODO: Integrar com:
        // - SendGrid/AWS SES para email
        // - Evolution API para WhatsApp
        // - Twilio para SMS

        // Por enquanto apenas loga
        \Log::info("Cobrança enviada por {$canal} para {$destinatario}", [
            'fatura_id' => $fatura->id,
            'numero_fatura' => $fatura->numero_fatura,
        ]);

        return true;
    }
}
