<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FaturamentoController extends Controller
{
    public function gerarBoleto($id, Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Boleto gerado (mock). Integração bancária pendente.',
            'boleto_url' => url("/storage/boletos/mock-boleto-{$id}.pdf"),
        ]);
    }
}
