<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nfse;
use App\Models\Fatura;
use Illuminate\Http\Request;

class NfseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Nfse::with(['fatura', 'cliente']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            $nfses = $query->orderBy('created_at', 'desc')->get();

            return response()->json(['success' => true, 'data' => $nfses]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function emitirLote(Request $request)
    {
        try {
            $validated = $request->validate([
                'fatura_ids' => 'required|array',
                'fatura_ids.*' => 'exists:faturas,id',
            ]);

            $nfses = [];
            
            foreach ($validated['fatura_ids'] as $faturaId) {
                $fatura = Fatura::find($faturaId);
                
                if (!$fatura->nfse_emitida) {
                    $nfse = Nfse::create([
                        'fatura_id' => $fatura->id,
                        'cliente_id' => $fatura->cliente_id,
                        'valor_servicos' => $fatura->valor_servicos,
                        'valor_iss' => $fatura->valor_servicos * 0.05,
                        'status' => 'pendente',
                    ]);
                    
                    $nfses[] = $nfse;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($nfses) . ' NFSe(s) criada(s)',
                'data' => $nfses
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function consultarProtocolo(Request $request)
    {
        try {
            $protocolo = $request->input('protocolo');
            $nfse = Nfse::where('protocolo', $protocolo)->first();

            if (!$nfse) {
                return response()->json(['success' => false, 'message' => 'Protocolo não encontrado'], 404);
            }

            return response()->json(['success' => true, 'data' => $nfse]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
