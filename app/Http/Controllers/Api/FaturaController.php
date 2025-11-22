<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel; // Importar facade

class FaturaController extends Controller
{
    /**
     * LISTAR FATURAS
     */
   
   protected $socImportService;


public function analisarArquivo(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls'
        ]);

        try {
            // Lê o arquivo para array (usando Maatwebsite ou nativo)
            $dados = Excel::toArray([], $request->file('file'))[0];
            
            // Chama o serviço de inteligência
            $analise = $this->socImportService->analisarArquivo($dados);

            return response()->json([
                'success' => true,
                'analise' => $analise
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao ler arquivo: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Passo 2: Recebe os dados APROVADOS pelo usuário e cria as faturas
     */
    public function processarLoteConfirmado(Request $request)
    {
        // Espera receber um array de faturas validadas pelo front
        $faturasAprovadas = $request->input('faturas'); 
        
        $geradas = 0;
        
        DB::beginTransaction();
        try {
            foreach ($faturasAprovadas as $faturaData) {
                // Lógica de criação (reutilizando o que fizemos antes, mas agora com dados limpos)
                // ... criar Fatura, Itens e Titulo ...
                // Use o ID do cliente que o usuário confirmou no front
                $this->criarFaturaDoLote($faturaData);
                $geradas++;
            }
            DB::commit();
            return response()->json(['success' => true, 'geradas' => $geradas]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function criarFaturaDoLote($dados) {
        // ... (Código de criação igual ao SocImportService anterior) ...
        // Aqui cria Fatura::create usando $dados['cliente_id'] e $dados['valor_total']
    }
}

    // Injeção de Dependência do Service
    public function __construct(SocImportService $socImportService)
    {
        $this->socImportService = $socImportService;
    }

    /**
     * Endpoint chamado pelo n8n para criar faturas em lote
     * Recebe um JSON com os dados da planilha já processados pela IA
     */
    public function importarLote(Request $request)
    {
        // Validação simples da estrutura
        $request->validate([
            'periodo' => 'required|string', // ex: 2025-10
            'itens' => 'required|array'     // Array flat de todos os itens da planilha
        ]);

        try {
            $resultado = $this->socImportService->processarLote(
                $request->input('itens'),
                $request->input('periodo')
            );

            return response()->json([
                'success' => true,
                'message' => "Processamento concluído. {$resultado['faturas_geradas']} faturas geradas.",
                'erros' => $resultado['erros']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro crítico na importação: ' . $e->getMessage()
            ], 500);
        }
    }
    public function index(Request $request)
    {
        try {
            $query = Fatura::with(['cliente', 'itens', 'titulos']);

            // Filtros
            if ($request->filled('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('periodo_referencia')) {
                $query->where('periodo_referencia', $request->periodo_referencia);
            }

            if ($request->filled('data_inicio')) {
                $query->whereDate('data_emissao', '>=', $request->data_inicio);
            }

            if ($request->filled('data_fim')) {
                $query->whereDate('data_emissao', '<=', $request->data_fim);
            }

            $query->orderBy('data_emissao', 'desc');

            $faturas = $request->filled('per_page')
                ? $query->paginate($request->per_page)
                : $query->get();

            return response()->json([
                'success' => true,
                'data' => $faturas
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar faturas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * CRIAR FATURA
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cliente_id' => 'required|exists:clientes,id',
            'data_emissao' => 'required|date',
            'data_vencimento' => 'required|date',
            'periodo_referencia' => 'required|string',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.valor_unitario' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $valorServicos = collect($validated['itens'])->sum(function ($i) {
                return $i['quantidade'] * $i['valor_unitario'];
            });

            $fatura = Fatura::create([
                'cliente_id' => $validated['cliente_id'],
                'numero_fatura' => $this->gerarNumeroFatura(),
                'data_emissao' => $validated['data_emissao'],
                'data_vencimento' => $validated['data_vencimento'],
                'periodo_referencia' => $validated['periodo_referencia'],
                'valor_servicos' => $valorServicos,
                'valor_total' => $valorServicos,
                'status' => 'aberta', // agora correto
            ]);

            foreach ($validated['itens'] as $index => $item) {
                FaturaItem::create([
                    'fatura_id' => $fatura->id,
                    'item_numero' => $index + 1,
                    'servico_id' => $item['servico_id'] ?? null,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'valor_total' => $item['quantidade'] * $item['valor_unitario'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fatura criada com sucesso',
                'data' => $fatura->load(['itens', 'cliente'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar fatura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * MOSTRAR FATURA
     */
    public function show($id)
    {
        try {
            $fatura = Fatura::with(['cliente', 'itens.servico', 'titulos'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $fatura
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Fatura não encontrada'
            ], 404);
        }
    }

    /**
     * ATUALIZAR FATURA
     */
    public function update(Request $request, $id)
    {
        try {
            $fatura = Fatura::findOrFail($id);

            if ($fatura->nfse_emitida) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fatura com NFSe emitida não pode ser alterada'
                ], 400);
            }

            $validated = $request->validate([
                'data_vencimento' => 'sometimes|date',
                'status' => 'sometimes|string',
                'valor_descontos' => 'nullable|numeric',
                'valor_acrescimos' => 'nullable|numeric',
                'valor_iss' => 'nullable|numeric',
                'observacoes' => 'nullable|string',
            ]);

            $statusAnterior = $fatura->status;

            $fatura->update($validated);

            $this->recalcularTotais($fatura->id);

            // SE STATUS MUDOU PARA EMITIDA → GERAR TÍTULO
            if (
                isset($validated['status']) &&
                $statusAnterior !== $validated['status'] &&
                in_array($validated['status'], ['emitida', 'fechada'])
            ) {
                $fatura->gerarTituloPadrao();
            }

            return response()->json([
                'success' => true,
                'data' => $fatura->fresh(['itens', 'titulos', 'cliente'])
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar fatura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETAR FATURA
     */
    public function destroy($id)
    {
        try {
            $fatura = Fatura::findOrFail($id);

            if ($fatura->nfse_emitida) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fatura com NFSe emitida não pode ser excluída'
                ], 400);
            }

            $fatura->delete();

            return response()->json([
                'success' => true,
                'message' => 'Fatura excluída com sucesso'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir fatura'
            ], 500);
        }
    }

    /**
     * ADICIONAR ITEM
     */
    public function adicionarItem(Request $request, $id)
    {
        try {
            $fatura = Fatura::findOrFail($id);

            if ($fatura->nfse_emitida) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível adicionar itens a fatura com NFSe emitida'
                ], 400);
            }

            $validated = $request->validate([
                'descricao' => 'required|string',
                'quantidade' => 'required|integer|min:1',
                'valor_unitario' => 'required|numeric|min:0',
            ]);

            $ultimo = FaturaItem::where('fatura_id', $id)->max('item_numero') ?? 0;

            FaturaItem::create([
                'fatura_id' => $id,
                'item_numero' => $ultimo + 1,
                'descricao' => $validated['descricao'],
                'quantidade' => $validated['quantidade'],
                'valor_unitario' => $validated['valor_unitario'],
                'valor_total' => $validated['quantidade'] * $validated['valor_unitario'],
            ]);

            $this->recalcularTotais($id);

            return response()->json([
                'success' => true,
                'message' => 'Item adicionado com sucesso',
                'data' => Fatura::with(['itens', 'cliente'])->find($id)
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erro ao adicionar item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RECALCULAR TOTAIS
     */
    private function recalcularTotais($id)
    {
        $fatura = Fatura::with('itens')->findOrFail($id);

        $valorServicos = $fatura->itens->sum('valor_total');

        $fatura->valor_servicos = $valorServicos;

        $fatura->valor_total =
            $valorServicos
            - ($fatura->valor_descontos ?? 0)
            + ($fatura->valor_acrescimos ?? 0)
            + ($fatura->valor_iss ?? 0);

        $fatura->save();
    }

    /**
     * GERAR NÚMERO DE FATURA
     */
    private function gerarNumeroFatura()
    {
        $ultimo = Fatura::max('id') ?? 0;
        return 'FAT-' . date('Ym') . '-' . str_pad($ultimo + 1, 6, '0', STR_PAD_LEFT);
    }
}
