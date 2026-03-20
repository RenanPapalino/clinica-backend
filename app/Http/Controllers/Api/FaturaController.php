<?php

namespace App\Http\Controllers\Api;

use App\Actions\Financeiro\CriarFaturaManualAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\FaturaResource;
use App\Models\Fatura;
use App\Models\Titulo;
use App\Models\Cliente;
use App\Models\Nfse;
use App\Models\FaturaItem;
use App\Services\SocImportService;
use App\Services\TributoService;
use App\Services\Bancos\ItauService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\Fiscal\NfseDiretaService;

class FaturaController extends Controller
{
    protected $socImportService;
    protected $tributoService;
    protected $criarFaturaManualAction;

    public function __construct(
        SocImportService $socImportService,
        TributoService $tributoService,
        CriarFaturaManualAction $criarFaturaManualAction,
    ) {
        $this->socImportService = $socImportService;
        $this->tributoService = $tributoService;
        $this->criarFaturaManualAction = $criarFaturaManualAction;
    }

    // ... (index, show, destroy mantidos iguais, focarei nas mudanças) ...

    public function index(Request $request)
    {
        try {
            $query = Fatura::with(['cliente', 'itens', 'titulos']);
            
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
    
            if ($request->filled('cliente_id')) $query->where('cliente_id', $request->cliente_id);
    
            $faturas = $query->orderBy('id', 'desc')->get();
    
            return response()->json([
                'success' => true,
                'data'    => FaturaResource::collection($faturas),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar faturas: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    public function show($id)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => new FaturaResource(Fatura::with(['cliente', 'itens', 'titulos'])->findOrFail($id)),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Fatura não encontrada'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $fatura = Fatura::with(['cliente', 'itens', 'titulos'])->findOrFail($id);

            $data = $request->validate([
                'data_emissao' => 'sometimes|date',
                'data_vencimento' => 'sometimes|date',
                'periodo_referencia' => 'sometimes|string|max:50',
                'valor_servicos' => 'sometimes|numeric|min:0',
                'valor_descontos' => 'sometimes|numeric|min:0',
                'valor_acrescimos' => 'sometimes|numeric|min:0',
                'valor_iss' => 'sometimes|numeric|min:0',
                'valor_total' => 'sometimes|numeric|min:0',
                'status' => 'sometimes|string|max:20',
                'observacoes' => 'nullable|string',
            ]);

            $statusAnterior = $fatura->status;
            $fatura->fill($data);
            $fatura->save();

            if (
                array_key_exists('status', $data)
                && $data['status'] === 'emitida'
                && $statusAnterior !== 'emitida'
            ) {
                $fatura->gerarTituloPadrao();
            }

            return response()->json([
                'success' => true,
                'message' => 'Fatura atualizada com sucesso.',
                'data' => new FaturaResource($fatura->fresh(['cliente', 'itens', 'titulos'])),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar fatura: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $fatura = Fatura::findOrFail($id);
            if ($fatura->nfse_emitida) return response()->json(['success' => false, 'message' => 'Fatura com NFSe não pode ser excluída'], 400);
            
            $fatura->delete(); // O banco deve ter cascade delete nos itens e titulos
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }

    public function destroyLote(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct',
        ]);

        $resultado = [
            'excluidas' => 0,
            'erros' => [],
        ];

        foreach ($data['ids'] as $id) {
            try {
                $fatura = Fatura::find($id);

                if (!$fatura) {
                    $resultado['erros'][] = "Fatura #{$id} não encontrada.";
                    continue;
                }

                if ($fatura->nfse_emitida) {
                    $resultado['erros'][] = "Fatura #{$id} possui NFS-e e não pode ser excluída.";
                    continue;
                }

                $fatura->delete();
                $resultado['excluidas']++;
            } catch (\Throwable $e) {
                $resultado['erros'][] = "Fatura #{$id}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'success' => $resultado['excluidas'] > 0,
            'message' => $resultado['excluidas'] > 0
                ? "Exclusão em lote concluída. {$resultado['excluidas']} fatura(s) removida(s)."
                : 'Nenhuma fatura foi excluída.',
            'data' => $resultado,
        ], !empty($resultado['erros']) && $resultado['excluidas'] === 0 ? 422 : 200);
    }

    public function processarLote(Request $request, ItauService $bancoService)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct',
        ]);

        $resultado = [
            'processadas' => 0,
            'nfse_emitidas' => 0,
            'boletos_gerados' => 0,
            'boletos_existentes' => 0,
            'itens' => [],
            'erros' => [],
        ];

        foreach ($data['ids'] as $id) {
            try {
                $fatura = Fatura::with(['cliente', 'itens', 'titulos.cliente'])->find($id);

                if (!$fatura) {
                    $resultado['erros'][] = "Fatura #{$id} não encontrada.";
                    continue;
                }

                $acoes = [];

                if ($this->deveEmitirNfse($fatura)) {
                    $this->emitirNfseLocal($fatura);
                    $resultado['nfse_emitidas']++;
                    $acoes[] = 'nfse_emitida';
                } elseif ($fatura->nfse_emitida) {
                    $acoes[] = 'nfse_ja_emitida';
                }

                $modoBoleto = $this->registrarBoletoFatura($fatura->fresh(['cliente', 'itens', 'titulos.cliente']), $bancoService);
                if ($modoBoleto === 'existing') {
                    $resultado['boletos_existentes']++;
                } else {
                    $resultado['boletos_gerados']++;
                }
                $acoes[] = $modoBoleto === 'existing' ? 'boleto_existente' : "boleto_{$modoBoleto}";

                $resultado['processadas']++;
                $resultado['itens'][] = [
                    'id' => $fatura->id,
                    'numero_fatura' => $fatura->numero_fatura,
                    'acoes' => $acoes,
                ];
            } catch (\Throwable $e) {
                $resultado['erros'][] = "Fatura #{$id}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'success' => $resultado['processadas'] > 0,
            'message' => $resultado['processadas'] > 0
                ? "Processamento em lote concluído. {$resultado['processadas']} fatura(s) tratada(s)."
                : 'Nenhuma fatura foi processada.',
            'data' => $resultado,
        ], !empty($resultado['erros']) && $resultado['processadas'] === 0 ? 422 : 200);
    }

    /**
     * CRIAÇÃO MANUAL INTELIGENTE
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'      => 'required|exists:clientes,id',
            'data_emissao'    => 'required|date',
            'data_vencimento' => 'required|date',
            'periodo_referencia' => 'required|string',
            'itens'           => 'required|array|min:1',
            'itens.*.servico_id' => 'nullable|exists:servicos,id',
            'itens.*.descricao' => 'required|string',
            'itens.*.valor_unitario' => 'required|numeric',
            'itens.*.quantidade' => 'required|numeric',
        ]);

        try {
            $fatura = $this->criarFaturaManualAction->execute($data);

            return response()->json([
                'success' => true,
                'data' => new FaturaResource($fatura),
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ANÁLISE DE ARQUIVO (SOC)
     */
    public function analisarArquivo(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,xlsx,xls']);
        
        try {
            $dados = Excel::toArray([], $request->file('file'))[0];
            $analise = $this->socImportService->analisarArquivo($dados);
            return response()->json(['success' => true, 'analise' => $analise]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro leitura: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PROCESSAMENTO DE LOTE (IA/SOC)
     */
    public function processarLoteConfirmado(Request $request)
    {
        $faturasData = $request->input('faturas');
        
        if (empty($faturasData)) return response()->json(['success' => false], 400);

        $geradas = 0;
        DB::beginTransaction();

        try {
            foreach ($faturasData as $dados) {
                // Revalida cliente e impostos antes de criar
                $cliente = Cliente::find($dados['cliente_id']);
                if(!$cliente) continue;

                $valorBruto = $dados['valor_total'];
                $impostos = $this->tributoService->calcularRetencoes($valorBruto, $cliente);

                $fatura = Fatura::create([
                    'cliente_id' => $cliente->id,
                    'numero_fatura' => 'FAT-' . date('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
                    'periodo_referencia' => date('Y-m'),
                    'data_emissao' => now(),
                    'data_vencimento' => now()->addDays($cliente->prazo_pagamento ?? 15),
                    'valor_servicos' => $valorBruto,
                    'valor_total' => $impostos['valor_liquido'],
                    'valor_iss' => $impostos['iss'],
                    'status' => 'pendente',
                    'observacoes' => "Importado via SOC (Lote)"
                ]);

                if (!empty($dados['itens'])) {
                    foreach ($dados['itens'] as $idx => $itemData) {
                        FaturaItem::create([
                            'fatura_id' => $fatura->id,
                            'servico_id' => $itemData['servico_id'] ?? null,
                            'item_numero' => $idx + 1,
                            'descricao' => $itemData['descricao'],
                            'quantidade' => 1,
                            'valor_unitario' => $itemData['valor'],
                            'valor_total' => $itemData['valor'],
                        ]);
                    }
                }

                $fatura->loadMissing('cliente');
                $fatura->gerarTituloPadrao();

                $geradas++;
            }

            DB::commit();
            return response()->json(['success' => true, 'geradas' => $geradas]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Adicionar item e recalcular impostos
     */
    public function adicionarItem(Request $request, $id)
    {
        try {
            $fatura = Fatura::with('cliente')->findOrFail($id);
            if ($fatura->status !== 'pendente') {
                return response()->json(['success' => false, 'message' => 'Fatura já processada'], 400);
            }

            $data = $request->validate([
                'descricao' => 'required', 'valor_unitario' => 'required|numeric', 'quantidade' => 'required|numeric'
            ]);

            $fatura->itens()->create([
                'item_numero' => $fatura->itens()->count() + 1,
                'descricao' => $data['descricao'],
                'quantidade' => $data['quantidade'],
                'valor_unitario' => $data['valor_unitario'],
                'valor_total' => $data['quantidade'] * $data['valor_unitario']
            ]);

            // Recalcula tudo (Crucial!)
            $novoBruto = $fatura->itens()->sum('valor_total');
            $impostos = $this->tributoService->calcularRetencoes($novoBruto, $fatura->cliente);
            
            $fatura->update([
                'valor_servicos' => $novoBruto,
                'valor_total' => $impostos['valor_liquido'],
                'valor_iss' => $impostos['iss']
            ]);

            // Atualiza título se existir
            Titulo::where('fatura_id', $fatura->id)->update([
                'valor_original' => $fatura->valor_total,
                'valor_saldo' => $fatura->valor_total // Assume que não foi pago ainda
            ]);

            return response()->json([
                'success' => true,
                'data' => new FaturaResource($fatura->fresh(['cliente', 'itens', 'titulos'])),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    // Método para compatibilidade com rota antiga
    public function importarLote(Request $request) {
        return $this->processarLoteConfirmado($request);
    }
    
    public function estatisticas() {
        return response()->json([
            'total_faturado' => Fatura::sum('valor_total'),
            'faturas_mes' => Fatura::whereMonth('created_at', now()->month)->count(),
            'pendentes' => Fatura::where('status', 'pendente')->count()
        ]);
    }

    public function importarSoc(Request $request)
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:csv,txt,xlsx,xls'
        ]);

        try {
            $file = $request->file('arquivo');
            
            // Salva temporariamente para processar
            $path = $file->storeAs('temp', 'import_soc_' . uniqid() . '.csv');
            $fullPath = storage_path('app/' . $path);

            $resultado = $this->socImportService->processarArquivo($fullPath);

            // Remove arquivo temporário
            @unlink($fullPath);

            return response()->json([
                'success' => true,
                'message' => 'Fatura importada com sucesso!',
                'data' => $resultado
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar arquivo: ' . $e->getMessage()
            ], 500);
        }
    }

    public function emitirNfse(Request $request, $id)
    {
        try {
            $fatura = Fatura::with(['cliente', 'itens', 'titulos'])->findOrFail($id);

            if ($fatura->nfse_emitida) {
                return response()->json(['success' => false, 'message' => 'NFS-e já emitida.'], 400);
            }

            $nfse = $this->emitirNfseLocal($fatura);

            return response()->json([
                'success' => true,
                'message' => 'NFSe registrada localmente. Envio à prefeitura não realizado neste ambiente.',
                'data' => [
                    'nfse' => $nfse,
                    'fatura' => new FaturaResource($fatura->fresh(['cliente', 'itens', 'titulos'])),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Erro Emissão NFS-e Fatura #$id: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro na emissão: ' . $e->getMessage()
            ], 500);
        }
    }

    private function deveEmitirNfse(Fatura $fatura): bool
    {
        if ($fatura->nfse_emitida) {
            return false;
        }

        return in_array($fatura->status, ['gerada', 'pendente', 'aberta'], true);
    }

    private function emitirNfseLocal(Fatura $fatura): Nfse
    {
        $this->recalcularTotaisFaturaSeNecessario($fatura);

        $valorServicos = $this->calcularValorServicos($fatura);
        $aliquotaIss = $fatura->cliente->aliquota_iss ?? 0;
        $valorIss = $aliquotaIss > 0 ? round($valorServicos * ($aliquotaIss / 100), 2) : 0;
        $valorLiquido = max($valorServicos - $valorIss, 0);
        $numeroGerado = 'NFSe-' . now()->format('Ymd') . '-' . str_pad($fatura->id, 4, '0', STR_PAD_LEFT);

        $nfse = Nfse::create([
            'fatura_id'      => $fatura->id,
            'cliente_id'     => $fatura->cliente_id,
            'numero_nfse'    => $numeroGerado,
            'data_emissao'   => now(),
            'data_envio'     => now(),
            'valor_servicos' => $valorServicos,
            'valor_iss'      => $valorIss,
            'aliquota_iss'   => $aliquotaIss,
            'valor_liquido'  => $valorLiquido,
            'status'         => 'pendente',
            'discriminacao'  => $fatura->observacoes,
            'pdf_url'        => null,
        ]);

        $statusEmitida = 'emitida';
        $dadosAtualizacao = [
            'nfse_emitida' => true,
            'nfse_numero'  => $numeroGerado,
        ];

        if ($this->statusAceitaValor($statusEmitida)) {
            $dadosAtualizacao['status'] = $statusEmitida;
        }

        $fatura->update($dadosAtualizacao);
        $fatura->refresh();

        return $nfse;
    }

    private function registrarBoletoFatura(Fatura $fatura, ItauService $bancoService): string
    {
        /** @var Titulo|null $titulo */
        $titulo = $fatura->titulos->firstWhere('tipo', 'receber');

        if (!$titulo) {
            $titulo = $fatura->gerarTituloPadrao();

            if (!$titulo) {
                throw new \RuntimeException('Não foi possível gerar um título financeiro para esta fatura.');
            }

            $titulo->load('cliente');
        } else {
            $titulo->loadMissing('cliente');
        }

        if (!$titulo->cliente) {
            throw new \RuntimeException('O título da fatura não possui cliente vinculado para registrar boleto.');
        }

        if (!empty($titulo->nosso_numero)) {
            return 'existing';
        }

        $mode = 'banco';

        try {
            $dadosBancarios = $bancoService->registrarBoleto($titulo);
        } catch (\Throwable $exception) {
            Log::warning('FATURAMENTO LOTE: falha ao registrar boleto no banco; aplicando fallback local.', [
                'fatura_id' => $fatura->id,
                'titulo_id' => $titulo->id,
                'error' => $exception->getMessage(),
            ]);

            $mode = 'local';
            $dadosBancarios = [
                'nosso_numero' => 'LOCAL' . str_pad((string) $titulo->id, 10, '0', STR_PAD_LEFT),
                'codigo_barras' => $titulo->codigo_barras,
                'linha_digitavel' => $titulo->linha_digitavel,
                'url_boleto' => $titulo->url_boleto,
            ];
        }

        $titulo->update([
            'nosso_numero' => $dadosBancarios['nosso_numero'] ?? $titulo->nosso_numero,
            'codigo_barras' => $dadosBancarios['codigo_barras'] ?? $titulo->codigo_barras,
            'linha_digitavel' => $dadosBancarios['linha_digitavel'] ?? $titulo->linha_digitavel,
            'url_boleto' => $dadosBancarios['url_boleto'] ?? $titulo->url_boleto,
            'status' => $titulo->status === 'pago' ? 'pago' : 'aberto',
        ]);

        if (in_array($fatura->status, ['emitida', 'nfse_emitida', 'aguardando_boleto'], true)) {
            $fatura->update(['status' => 'concluida']);
        }

        return $mode;
    }

    /**
     * Obtém o valor de serviços da fatura com múltiplos fallbacks.
     */
    private function calcularValorServicos(Fatura $fatura): float
    {
        // 1) Tenta usar valores já salvos na fatura
        $valor = $fatura->valor_servicos;
        if ($valor !== null && $valor > 0) {
            return (float) $valor;
        }

        if ($fatura->valor_total !== null && $fatura->valor_total > 0) {
            return (float) $fatura->valor_total;
        }

        // 2) Usa os itens já carregados (valor_total ou quantidade x valor_unitario)
        $valor = $fatura->itens->sum('valor_total');
        if ($valor <= 0) {
            $valor = $fatura->itens->sum(function ($item) {
                $qtd = $item->quantidade ?? 0;
                $unit = $item->valor_unitario ?? 0;
                return $qtd * $unit;
            });
        }
        if ($valor > 0) {
            return (float) $valor;
        }

        // 3) Consulta direta ao banco (sem cache), incluindo cálculo por quantidade x unitário
        $valor = FaturaItem::where('fatura_id', $fatura->id)->sum('valor_total');
        if ($valor <= 0) {
            $valor = FaturaItem::where('fatura_id', $fatura->id)
                ->selectRaw('SUM(COALESCE(valor_unitario * quantidade, 0)) as total')
                ->value('total');
        }
        if ($valor > 0) {
            return (float) $valor;
        }

        // 4) Fallback: tenta usar títulos vinculados
        $valorTitulos = Titulo::where('fatura_id', $fatura->id)->sum('valor_original');
        if ($valorTitulos > 0) {
            return (float) $valorTitulos;
        }

        return 0.0;
    }

    /**
     * Recalcula valores da fatura a partir dos itens caso estejam zerados.
     */
    private function recalcularTotaisFaturaSeNecessario(Fatura $fatura): void
    {
        $itens = $fatura->itens;
        if ($itens->isEmpty()) {
            return;
        }

        // Soma valor_total; se não houver, recalcula a partir de quantidade x valor_unitario
        $valorItens = $itens->sum('valor_total');
        if ($valorItens <= 0) {
            $valorItens = $itens->sum(function ($item) {
                $qtd = $item->quantidade ?? 0;
                $unit = $item->valor_unitario ?? 0;
                return $qtd * $unit;
            });
        }

        $dados = [];
        if ($valorItens > 0) {
            if ($fatura->valor_servicos <= 0) {
                $dados['valor_servicos'] = $valorItens;
            }
            if ($fatura->valor_total <= 0) {
                $dados['valor_total'] = $valorItens;
            }
        }

        // Se ainda não tem valor_total e há títulos com valor, usa-os como fallback
        if (($dados['valor_total'] ?? $fatura->valor_total) <= 0) {
            $valorTitulos = Titulo::where('fatura_id', $fatura->id)->sum('valor_original');
            if ($valorTitulos > 0) {
                $dados['valor_total'] = $valorTitulos;
                if (($dados['valor_servicos'] ?? $fatura->valor_servicos) <= 0) {
                    $dados['valor_servicos'] = $valorTitulos;
                }
            }
        }

        if ($fatura->valor_iss === null && $fatura->cliente && $fatura->cliente->aliquota_iss) {
            $aliquotaIss = $fatura->cliente->aliquota_iss;
            $base = $dados['valor_servicos'] ?? $fatura->valor_servicos ?? $valorItens;
            $dados['valor_iss'] = round($base * ($aliquotaIss / 100), 2);
        }

        if (!empty($dados)) {
            $fatura->update($dados);
            $fatura->refresh();
        }
    }

    /**
     * Verifica se a coluna status aceita o valor informado (para bancos com ENUM).
     */
    private function statusAceitaValor(string $valor): bool
    {
        try {
            $coluna = collect(DB::select("SHOW COLUMNS FROM faturas WHERE Field = 'status'"))->first();

            if ($coluna && isset($coluna->Type) && str_starts_with($coluna->Type, 'enum(')) {
                preg_match_all("/'([^']+)'/", $coluna->Type, $matches);
                $permitidos = $matches[1] ?? [];

                return in_array($valor, $permitidos, true);
            }
        } catch (\Throwable $e) {
            // Se não conseguir ler metadata, não bloqueia a atualização
        }

        return true;
    }
}
