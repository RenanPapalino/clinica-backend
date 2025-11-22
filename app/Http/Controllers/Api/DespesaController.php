<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Despesa;
use App\Models\Fornecedor;
use App\Models\CategoriaDespesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http; // Para chamar o n8n

class DespesaController extends Controller
{
    public function index(Request $request)
    {
        $query = Despesa::with(['fornecedor', 'categoria']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Ordenar por vencimento (urgência)
        $despesas = $query->orderBy('data_vencimento')->paginate(20);

        return response()->json(['success' => true, 'data' => $despesas]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'descricao' => 'required|string',
            'valor' => 'required|numeric',
            'data_vencimento' => 'required|date',
            'fornecedor_id' => 'nullable|exists:fornecedores,id',
            'categoria_id' => 'nullable|exists:categorias_despesa,id',
            'documento_url' => 'nullable|string'
        ]);

        $data['data_emissao'] = $request->input('data_emissao', now());
        $despesa = Despesa::create($data);

        return response()->json(['success' => true, 'data' => $despesa]);
    }

    /**
     * A "Mágica" do ERP: Upload Inteligente
     * Recebe um arquivo, salva e envia para IA (n8n) extrair dados.
     */
    public function analisarDocumento(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png']);

        $file = $request->file('file');
        $path = $file->store('despesas', 'public');
        $url = asset('storage/' . $path);

        // --- INTEGRAÇÃO COM IA (Simulação ou Real) ---
        // Aqui você chamaria o n8n passando a URL do arquivo.
        // O n8n usaria Google Vision ou OpenAI Vision para ler: Data, Valor, CNPJ.
        
        // Exemplo de chamada real (descomente quando tiver o n8n pronto):
        /*
        $response = Http::post(env('N8N_OCR_WEBHOOK'), ['file_url' => $url]);
        $dadosExtraidos = $response->json();
        */

        // MOCK (Simulando que a IA leu o boleto):
        $dadosExtraidos = [
            'valor' => 1250.50,
            'data_vencimento' => now()->addDays(10)->format('Y-m-d'),
            'descricao' => 'Boleto Detectado (IA)',
            'codigo_barras' => '34191.79001 01043.510047 91020.150008 6 89850000125050',
            'fornecedor_sugerido' => 'Enel Distribuição'
        ];

        return response()->json([
            'success' => true,
            'documento_url' => $url,
            'dados_sugeridos' => $dadosExtraidos
        ]);
    }

    public function pagar($id)
    {
        $despesa = Despesa::findOrFail($id);
        $despesa->update([
            'status' => 'pago',
            'data_pagamento' => now(),
            'valor_pago' => $despesa->valor 
        ]);
        return response()->json(['success' => true, 'message' => 'Despesa paga']);
    }
}