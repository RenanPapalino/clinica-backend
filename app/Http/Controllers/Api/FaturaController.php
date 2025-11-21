<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FaturaController extends Controller
{
    /**
     * Listar faturas
     */
    public function index(Request $request)
    {
        try {
            $query = Fatura::with(['cliente', 'itens']);

            // Filtros
            if ($request->has('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            if ($request->has('periodo')) {
                $query->where('periodo_referencia', $request->periodo);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('data_inicio')) {
                $query->where('data_emissao', '>=', $request->data_inicio);
            }

            if ($request->has('data_fim')) {
                $query->where('data_emissao', '<=', $request->data_fim);
            }

            // Paginação ou listagem completa
            if ($request->has('per_page')) {
                $faturas = $query->orderBy('data_emissao', 'desc')->paginate($request->per_page);
            } else {
                $faturas = $query->orderBy('data_emissao', 'desc')->get();
            }

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
     * Criar nova fatura
     */
    public function store(Request $request)
    {
        try {
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

            DB::beginTransaction();

            // Calcular totais
            $valorServicos = 0;
            foreach ($validated['itens'] as $item) {
                $valorServicos += $item['quantidade'] * $item['valor_unitario'];
            }

            // Criar fatura
            $fatura = Fatura::create([
                'cliente_id' => $validated['cliente_id'],
                'numero_fatura' => $this->gerarNumeroFatura(),
                'data_emissao' => $validated['data_emissao'],
                'data_vencimento' => $validated['data_vencimento'],
                'periodo_referencia' => $validated['periodo_referencia'],
                'valor_servicos' => $valorServicos,
                'valor_total' => $valorServicos,
                'status' => 'emitida',
            ]);

            // Criar itens
            foreach ($validated['itens'] as $index => $item) {
                FaturaItem::create([
                    'fatura_id' => $fatura->id,
                    'servico_id' => $item['servico_id'] ?? null,
                    'item_numero' => $index + 1,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'valor_total' => $item['quantidade'] * $item['valor_unitario'],
                    'funcionario' => $item['funcionario'] ?? null,
                    'matricula' => $item['matricula'] ?? null,
                    'data_realizacao' => $item['data_realizacao'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fatura criada com sucesso',
                'data' => $fatura->load(['itens', 'cliente'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
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
     * Ver fatura específica
     */
    public function show($id)
    {
        try {
            $fatura = Fatura::with(['cliente', 'itens', 'nfse'])->findOrFail($id);

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
     * Atualizar fatura
     */
    public function update(Request $request, $id)
    {
        try {
            $fatura = Fatura::findOrFail($id);

            // Não permitir editar fatura já com NFSe
            if ($fatura->nfse_emitida) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fatura com NFSe emitida não pode ser alterada'
                ], 400);
            }

            $validated = $request->validate([
                'data_vencimento' => 'sometimes|date',
                'status' => 'sometimes|in:rascunho,emitida,cancelada',
                'observacoes' => 'nullable|string',
            ]);

            $fatura->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Fatura atualizada com sucesso',
                'data' => $fatura->load(['itens', 'cliente'])
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
     * Deletar fatura
     */
    public function destroy($id)
    {
        try {
            $fatura = Fatura::findOrFail($id);

            // Não permitir deletar fatura já com NFSe
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
                'message' => 'Erro ao excluir fatura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Adicionar item à fatura
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

            $ultimoItem = FaturaItem::where('fatura_id', $fatura->id)->max('item_numero') ?? 0;

            $item = FaturaItem::create([
                'fatura_id' => $fatura->id,
                'item_numero' => $ultimoItem + 1,
                'descricao' => $validated['descricao'],
                'quantidade' => $validated['quantidade'],
                'valor_unitario' => $validated['valor_unitario'],
                'valor_total' => $validated['quantidade'] * $validated['valor_unitario'],
            ]);

            // Recalcular totais da fatura
            $valorTotal = FaturaItem::where('fatura_id', $fatura->id)->sum('valor_total');
            $fatura->update([
                'valor_servicos' => $valorTotal,
                'valor_total' => $valorTotal,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item adicionado com sucesso',
                'data' => $item
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao adicionar item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estatísticas de faturamento
     */
    public function estatisticas(Request $request)
    {
        try {
            $periodo = $request->input('periodo', date('Y-m'));

            $stats = [
                'total_faturas' => Fatura::where('periodo_referencia', $periodo)->count(),
                'valor_total' => Fatura::where('periodo_referencia', $periodo)->sum('valor_total'),
                'faturas_emitidas' => Fatura::where('periodo_referencia', $periodo)
                    ->where('status', 'emitida')->count(),
                'nfse_emitidas' => Fatura::where('periodo_referencia', $periodo)
                    ->where('nfse_emitida', true)->count(),
                'top_clientes' => Fatura::select('cliente_id', DB::raw('SUM(valor_total) as total'))
                    ->with('cliente:id,razao_social')
                    ->where('periodo_referencia', $periodo)
                    ->groupBy('cliente_id')
                    ->orderBy('total', 'desc')
                    ->limit(5)
                    ->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gerar número de fatura
     */
    private function gerarNumeroFatura()
    {
        $ultimo = Fatura::max('id') ?? 0;
        return 'FAT-' . date('Ym') . '-' . str_pad($ultimo + 1, 6, '0', STR_PAD_LEFT);
    }
}
