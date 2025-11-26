<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdemServico;
use App\Models\Fatura;
use App\Services\SocImportService;
use App\Services\FaturamentoService;
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
    // 1. Instancia o serviço manualmente para garantir que erros de classe sejam capturados
    try {
        // Validação
        $request->validate([
            'arquivo' => 'required|file',
            'cliente_id' => 'required'
        ]);

        // Instanciação manual (Evita erro 500 antes de entrar no método)
        $service = new \App\Services\SocImportService();

        $result = $service->importar(
            $request->file('arquivo'),
            $request->input('cliente_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Importação realizada com sucesso!',
            'data' => $result
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        // Erros de validação (ex: arquivo faltando)
        return response()->json([
            'success' => false, 
            'message' => 'Erro de Validação: ' . $e->getMessage(),
            'errors' => $e->errors()
        ], 422);

    } catch (\Throwable $e) {
        // CAPTURA QUALQUER OUTRO ERRO E MOSTRA NO FRONTEND
        // Loga no arquivo do servidor para garantia
        \Illuminate\Support\Facades\Log::error('Erro Importação: ' . $e->getMessage());
        \Illuminate\Support\Facades\Log::error($e->getTraceAsString());

        return response()->json([
            'success' => false,
            'message' => 'ERRO DETALHADO: ' . $e->getMessage() . ' | Linha: ' . $e->getLine() . ' | Arquivo: ' . basename($e->getFile())
        ], 500);
    }
}

public function faturar(Request $request, $id, FaturamentoService $faturamentoService)
    {
        try {
            $fatura = $faturamentoService->gerarFaturaDeOS($id);

            return response()->json([
                'success' => true,
                'message' => "Fatura #{$fatura->numero_fatura} gerada com sucesso!",
                'fatura_id' => $fatura->id
            ]);

        } catch (\Throwable $e) {
            Log::error("Erro ao faturar OS {$id}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar faturamento: ' . $e->getMessage()
            ], 500);
        }
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