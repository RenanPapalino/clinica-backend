<?php

namespace App\Http\Controllers\Api;

use DomainException;
use App\Actions\Financeiro\BaixarTituloAction;
use App\Actions\Financeiro\CriarTituloAction;
use App\Http\Controllers\Controller;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Services\Bancos\ItauService;

class TituloController extends Controller
{
    /**
     * Lista de títulos (com filtros avançados)
     */
    public function index(Request $request)
    {
        // Carrega relacionamentos importantes para a listagem
        $query = Titulo::with(['cliente', 'fatura', 'fornecedor', 'planoConta', 'centroCusto'])
            ->orderBy('data_vencimento')
            ->orderBy('id');

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        if ($request->filled('fornecedor_id')) { // Filtro novo para Contas a Pagar
            $query->where('fornecedor_id', $request->fornecedor_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('vencimento_de')) {
            $query->whereDate('data_vencimento', '>=', $request->vencimento_de);
        }

        if ($request->filled('vencimento_ate')) {
            $query->whereDate('data_vencimento', '<=', $request->vencimento_ate);
        }

        // Filtro simples por 'atrasado'
        if ($request->has('atrasados') && $request->atrasados == 'true') {
            $query->where('status', '!=', 'pago')
                  ->whereDate('data_vencimento', '<', Carbon::today());
        }

        $perPage = (int) ($request->get('per_page', 20));

        $titulos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $titulos,
        ]);
    }

    /**
     * Cria um novo título (Unificado: Simples + Rateio + Transação)
     */
    public function store(Request $request, CriarTituloAction $criarTituloAction)
    {
        $data = $request->validate([
            'descricao'       => ['required', 'string', 'max:255'],
            'cliente_id'      => ['nullable', 'exists:clientes,id'],
            'fornecedor_id'   => ['nullable', 'exists:fornecedores,id'],
            'fatura_id'       => ['nullable', 'exists:faturas,id'],
            'plano_conta_id'  => ['nullable', 'exists:planos_contas,id'],
            'centro_custo_id' => ['nullable', 'exists:centros_custo,id'],
            'numero_titulo'   => ['nullable', 'string', 'max:50'],
            'nosso_numero'    => ['nullable', 'string', 'max:50'],
            'data_emissao'    => ['required', 'date'],
            'data_vencimento' => ['required', 'date'],
            'competencia'     => ['nullable', 'date'],
            'valor_original'  => ['required', 'numeric', 'min:0.01'],
            'valor_juros'     => ['nullable', 'numeric', 'min:0'],
            'valor_multa'     => ['nullable', 'numeric', 'min:0'],
            'valor_desconto'  => ['nullable', 'numeric', 'min:0'],
            'status'          => ['required', 'string', 'max:20'],
            'tipo'            => ['required', 'in:pagar,receber'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'codigo_barras'   => ['nullable', 'string', 'max:255'],
            'linha_digitavel' => ['nullable', 'string', 'max:255'],
            'url_boleto'      => ['nullable', 'string', 'max:255'],
            'observacoes'     => ['nullable', 'string'],
            'rateios'                   => ['nullable', 'array'],
            'rateios.*.plano_conta_id'  => ['required_with:rateios', 'exists:planos_contas,id'],
            'rateios.*.centro_custo_id' => ['nullable', 'exists:centros_custo,id'],
            'rateios.*.valor'           => ['required_with:rateios', 'numeric', 'min:0.01'],
            'rateios.*.percentual'      => ['nullable', 'numeric', 'min:0'],
            'rateios.*.historico'       => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $titulo = $criarTituloAction->execute($data);
            return response()->json([
                'success' => true,
                'message' => 'Título criado com sucesso.',
                'data' => $titulo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao criar título: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Detalhe do título
     */
    public function show($id)
    {
        try {
            // Carrega também os rateios para exibir na tela de detalhes
            $titulo = Titulo::with(['cliente', 'fatura', 'cobrancas', 'rateios.planoConta', 'rateios.centroCusto'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $titulo,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Título não encontrado'], 404);
        }
    }

    /**
     * Atualiza título
     */
    public function update(Request $request, $id)
    {
        try {
            $titulo = Titulo::findOrFail($id);

            $data = $request->validate([
                'data_vencimento' => ['sometimes', 'date'],
                'data_pagamento'  => ['nullable', 'date'],
                'valor_original'  => ['sometimes', 'numeric', 'min:0'],
                'valor_juros'     => ['nullable', 'numeric', 'min:0'],
                'valor_multa'     => ['nullable', 'numeric', 'min:0'],
                'valor_desconto'  => ['nullable', 'numeric', 'min:0'],
                'valor_pago'      => ['nullable', 'numeric', 'min:0'],
                'status'          => ['sometimes', 'string', 'max:20'],
                'forma_pagamento' => ['nullable', 'string', 'max:30'],
                'observacoes'     => ['nullable', 'string'],
                // TODO: Adicionar lógica para atualizar rateios se o valor mudar
            ]);

            $titulo->fill($data);

            // Recalcula saldo se valores mudaram
            if ($titulo->isDirty(['valor_original', 'valor_juros', 'valor_multa', 'valor_desconto', 'valor_pago'])) {
                $titulo->valor_saldo = ($titulo->valor_original + ($titulo->valor_juros ?? 0) + ($titulo->valor_multa ?? 0)) 
                                     - (($titulo->valor_desconto ?? 0) + ($titulo->valor_pago ?? 0));
                
                // Garante status correto
                if ($titulo->valor_saldo <= 0 && $titulo->valor_pago > 0) {
                    $titulo->status = 'pago';
                }
            }

            $titulo->save();

            return response()->json([
                'success' => true,
                'message' => 'Título atualizado.',
                'data' => $titulo->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove (soft delete)
     */
    public function destroy($id)
    {
        try {
            $titulo = Titulo::findOrFail($id);
            $titulo->delete();

            return response()->json([
                'success' => true,
                'message' => 'Título removido com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }

    /**
     * Baixar título (registrar pagamento)
     */
    public function baixar(Request $request, $id, BaixarTituloAction $baixarTituloAction)
    {
        $data = $request->validate([
            'valor'           => ['required', 'numeric', 'min:0.01'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'data_pagamento'  => ['nullable', 'date'],
        ]);

        try {
            $titulo = $baixarTituloAction->execute(
                (int) $id,
                (float) $data['valor'],
                $data['forma_pagamento'] ?? null,
                $data['data_pagamento'] ?? null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Baixa registrada com sucesso.',
                'data' => $titulo,
            ]);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao baixar título: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Relatório de aging
     */
    public function relatorioAging(Request $request)
    {
        $hoje = Carbon::today();

        $base = Titulo::query()->whereNotIn('status', ['pago', 'cancelado']);

        if ($request->filled('cliente_id')) {
            $base->where('cliente_id', $request->cliente_id);
        }
        
        // Filtro para separar Contas a Pagar vs Receber no Aging
        if ($request->filled('tipo')) {
            $base->where('tipo', $request->tipo);
        }

        $titulos = $base->get();

        $buckets = [
            'atual'    => 0.0,
            'ate_30'   => 0.0,
            'de_31_60' => 0.0,
            'de_61_90' => 0.0,
            'acima_90' => 0.0,
        ];

        foreach ($titulos as $titulo) {
            $saldo = (float) ($titulo->valor_saldo > 0 ? $titulo->valor_saldo : $titulo->valor_original);

            if ($saldo <= 0) continue;

            $venc = Carbon::parse($titulo->data_vencimento);
            $dias = $venc->diffInDays($hoje, false); // Positivo = Atrasado

            if ($dias <= 0) {
                $buckets['atual'] += $saldo;
            } elseif ($dias <= 30) {
                $buckets['ate_30'] += $saldo;
            } elseif ($dias <= 60) {
                $buckets['de_31_60'] += $saldo;
            } elseif ($dias <= 90) {
                $buckets['de_61_90'] += $saldo;
            } else {
                $buckets['acima_90'] += $saldo;
            }
        }

        $response = [];
        foreach ($buckets as $faixa => $valor) {
            $response[] = ['faixa' => $faixa, 'valor' => round($valor, 2)];
        }

        return response()->json(['success' => true, 'data' => $response]);
    }

    public function registrarBoleto(Request $request, $id, ItauService $bancoService)
    {
        try {
            $titulo = Titulo::with('cliente')->findOrFail($id);

            if ($titulo->tipo !== 'receber') {
                return response()->json(['success' => false, 'message' => 'Apenas contas a receber geram boleto.'], 400);
            }

            if (!empty($titulo->nosso_numero)) {
                return response()->json(['success' => false, 'message' => 'Boleto já registrado.'], 400);
            }

            // Chama o serviço bancário
            $dadosBancarios = $bancoService->registrarBoleto($titulo);

            // Atualiza o título com o retorno do banco
            $titulo->update([
                'nosso_numero' => $dadosBancarios['nosso_numero'],
                'codigo_barras' => $dadosBancarios['codigo_barras'],
                'linha_digitavel' => $dadosBancarios['linha_digitavel'],
                'status' => 'aberto', // Confirma que está aberto e registrado
                'url_boleto' => $dadosBancarios['url_boleto'] // Se houver
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Boleto registrado com sucesso no Itaú!',
                'data' => $titulo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro na comunicação bancária: ' . $e->getMessage()
            ], 500);
        }
    }
}
