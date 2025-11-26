<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdemServico;
use App\Models\Fatura;
use App\Services\SocImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdemServicoController extends Controller
{
    protected $socService;

    public function __construct(SocImportService $socService)
    {
        $this->socService = $socService;
    }

    public function index(Request $request)
    {
        $query = OrdemServico::with('cliente')->orderByDesc('id');
        if ($request->has('status')) $query->where('status', $request->status);
        
        return response()->json([
            'success' => true,
            'data' => $query->paginate(20)
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => OrdemServico::with(['itens', 'cliente', 'fatura'])->findOrFail($id)
        ]);
    }

    public function importarSoc(Request $request)
    {
        $request->validate(['arquivo' => 'required|file']);
        
        try {
            $path = $request->file('arquivo')->store('temp');
            $os = $this->socService->processarArquivo(storage_path('app/' . $path));
            
            return response()->json([
                'success' => true,
                'message' => 'Ordem de Serviço criada com sucesso!',
                'data' => $os
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Ação principal: Transforma OS Aprovada em Fatura Real
    public function faturar($id)
    {
        return DB::transaction(function () use ($id) {
            $os = OrdemServico::with('itens')->findOrFail($id);

            if ($os->status === 'faturada') {
                return response()->json(['success' => false, 'message' => 'OS já faturada.'], 400);
            }

            // 1. Cria Fatura
            $fatura = Fatura::create([
                'cliente_id' => $os->cliente_id,
                'numero_fatura' => 'FAT-' . $os->id, // Exemplo
                'data_emissao' => now(),
                'data_vencimento' => now()->addDays(15),
                'valor_total' => $os->valor_total,
                'status' => 'pendente', // Pronta para emitir NFSe
                'observacoes' => "Gerado a partir da OS #{$os->codigo_os}"
            ]);

            // 2. Copia Itens
            foreach ($os->itens as $item) {
                $fatura->itens()->create([
                    'descricao' => $item->descricao,
                    'quantidade' => $item->quantidade,
                    'valor_unitario' => $item->valor_unitario,
                    'valor_total' => $item->valor_total,
                    'observacoes' => $item->unidade_soc ? "Unidade: {$item->unidade_soc}" : null
                ]);
            }

            // 3. Atualiza OS
            $os->update([
                'status' => 'faturada',
                'fatura_gerada_id' => $fatura->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fatura gerada com sucesso!',
                'data' => $fatura
            ]);
        });
    }
}

public function store(Request $request)
    {
        $request->validate([
            'cliente_id' => 'required|exists:clientes,id',
            'competencia' => 'required|string|size:7', // Ex: 10/2023
            'data_emissao' => 'required|date',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.valor' => 'required|numeric|min:0',
            'itens.*.quantidade' => 'required|integer|min:1'
        ]);

        return DB::transaction(function () use ($request) {
            // 1. Cria o Cabeçalho
            $valorTotal = collect($request->itens)->sum(fn($i) => $i['valor'] * $i['quantidade']);

            $os = OrdemServico::create([
                'cliente_id' => $request->cliente_id,
                'codigo_os' => 'OS-MAN-' . date('ymd-His'),
                'competencia' => $request->competencia,
                'data_emissao' => $request->data_emissao,
                'valor_total' => $valorTotal,
                'status' => 'pendente',
                'observacoes' => $request->observacoes ?? 'Criação Manual'
            ]);

            // 2. Cria os Itens
            foreach ($request->itens as $item) {
                $os->itens()->create([
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor'],
                    'valor_total' => $item['valor'] * $item['quantidade'],
                    'unidade_soc' => 'Manual',
                    'centro_custo_cliente' => 'N/A'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'OS Manual criada com sucesso!',
                'data' => $os->load('itens')
            ]);
        });
    }