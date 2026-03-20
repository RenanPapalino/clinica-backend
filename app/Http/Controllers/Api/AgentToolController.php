<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cadastros\CriarClienteAction;
use App\Actions\Financeiro\CriarDespesaAction;
use App\Actions\Financeiro\CriarTituloAction;
use App\Actions\Rag\SearchRagChunksAction;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Fatura;
use App\Models\Fornecedor;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AgentToolController extends Controller
{
    public function sessionContext(Request $request)
    {
        $data = $request->validate([
            'session_id' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $limit = $data['limit'] ?? config('chatbot.runtime.default_history_limit', 20);

        $messages = ChatMessage::where('user_id', $user->id)
            ->where('session_id', $data['session_id'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'session_id' => $data['session_id'],
                'messages' => $messages,
            ],
        ]);
    }

    public function searchKnowledge(Request $request, SearchRagChunksAction $searchRagChunksAction)
    {
        $data = $request->validate([
            'query' => 'required|string|min:2',
            'business_context' => 'nullable|string|max:120',
            'context_key' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        return response()->json([
            'success' => true,
            'data' => $searchRagChunksAction->execute($data['query'], $data),
        ]);
    }

    public function financialSummary()
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        return response()->json([
            'success' => true,
            'data' => [
                'clientes_ativos' => Cliente::where('status', 'ativo')->count(),
                'faturamento_mes' => (float) Fatura::whereBetween('data_emissao', [$monthStart, $monthEnd])->sum('valor_total'),
                'receber_aberto' => (float) Titulo::whereIn('status', ['aberto', 'pendente', 'parcial'])->where('tipo', 'receber')->sum('valor_saldo'),
                'titulos_vencidos' => Titulo::where('tipo', 'receber')->where('status', '!=', 'pago')->whereDate('data_vencimento', '<', $today)->count(),
                'pagar_aberto' => (float) Despesa::whereIn('status', ['pendente', 'atrasado'])->sum('valor'),
            ],
        ]);
    }

    public function searchClientes(Request $request)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = Cliente::query()->orderBy('razao_social');
        $limit = $data['limit'] ?? 20;

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $query->where(function ($builder) use ($termo) {
                $builder->where('razao_social', 'like', "%{$termo}%")
                    ->orWhere('nome_fantasia', 'like', "%{$termo}%")
                    ->orWhere('cnpj', 'like', '%' . preg_replace('/\D/', '', $termo) . '%');
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit($limit)->get(),
        ]);
    }

    public function searchTitulos(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'tipo' => 'nullable|in:receber,pagar',
            'status' => 'nullable|string|max:20',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Titulo::with(['cliente', 'fornecedor'])->orderBy('data_vencimento');

        if (!empty($data['cliente_id'])) {
            $query->where('cliente_id', $data['cliente_id']);
        }

        if (!empty($data['tipo'])) {
            $query->where('tipo', $data['tipo']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit($data['limit'] ?? 20)->get(),
        ]);
    }

    public function searchDespesas(Request $request)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:255',
            'fornecedor_id' => 'nullable|integer|exists:fornecedores,id',
            'status' => 'nullable|string|max:20',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Despesa::with(['fornecedor', 'categoria', 'planoConta'])
            ->orderBy('data_vencimento')
            ->orderBy('id');

        if (!empty($data['fornecedor_id'])) {
            $query->where('fornecedor_id', $data['fornecedor_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $query->where(function ($builder) use ($termo) {
                $builder->where('descricao', 'like', "%{$termo}%")
                    ->orWhere('codigo_barras', 'like', "%{$termo}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit($data['limit'] ?? 20)->get(),
        ]);
    }

    public function searchFornecedores(Request $request)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = Fornecedor::query()->orderBy('razao_social');
        $limit = $data['limit'] ?? 20;

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $documento = preg_replace('/\D/', '', $termo);

            $query->where(function ($builder) use ($termo, $documento) {
                $builder->where('razao_social', 'like', "%{$termo}%")
                    ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                if ($documento !== '') {
                    $builder->orWhere('cnpj', 'like', "%{$documento}%")
                        ->orWhere('cpf', 'like', "%{$documento}%");
                }
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit($limit)->get(),
        ]);
    }

    public function createCliente(Request $request, CriarClienteAction $criarClienteAction)
    {
        $data = $request->validate([
            'cnpj' => 'required|string',
            'razao_social' => 'required|string|max:200',
            'nome_fantasia' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:100',
            'telefone' => 'nullable|string|max:20',
            'celular' => 'nullable|string|max:20',
            'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|max:2',
            'status' => 'nullable|in:ativo,inativo',
        ]);

        $cliente = $criarClienteAction->execute($data);

        return response()->json([
            'success' => true,
            'message' => 'Cliente criado com sucesso.',
            'data' => $cliente,
        ], 201);
    }

    public function createContaReceber(Request $request, CriarTituloAction $criarTituloAction)
    {
        $data = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
            'cliente_id' => ['required', 'exists:clientes,id'],
            'fornecedor_id' => ['nullable', 'exists:fornecedores,id'],
            'fatura_id' => ['nullable', 'exists:faturas,id'],
            'plano_conta_id' => ['nullable', 'exists:planos_contas,id'],
            'centro_custo_id' => ['nullable', 'exists:centros_custo,id'],
            'numero_titulo' => ['nullable', 'string', 'max:50'],
            'nosso_numero' => ['nullable', 'string', 'max:50'],
            'data_emissao' => ['nullable', 'date'],
            'data_vencimento' => ['required', 'date'],
            'competencia' => ['nullable', 'date'],
            'valor_original' => ['required', 'numeric', 'min:0.01'],
            'valor_juros' => ['nullable', 'numeric', 'min:0'],
            'valor_multa' => ['nullable', 'numeric', 'min:0'],
            'valor_desconto' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:20'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'codigo_barras' => ['nullable', 'string', 'max:255'],
            'linha_digitavel' => ['nullable', 'string', 'max:255'],
            'url_boleto' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'rateios' => ['nullable', 'array'],
            'rateios.*.plano_conta_id' => ['required_with:rateios', 'exists:planos_contas,id'],
            'rateios.*.centro_custo_id' => ['nullable', 'exists:centros_custo,id'],
            'rateios.*.valor' => ['required_with:rateios', 'numeric', 'min:0.01'],
            'rateios.*.percentual' => ['nullable', 'numeric', 'min:0'],
            'rateios.*.historico' => ['nullable', 'string', 'max:255'],
        ]);

        $data['data_emissao'] = $data['data_emissao'] ?? now()->toDateString();
        $data['status'] = $data['status'] ?? 'aberto';
        $data['tipo'] = 'receber';

        $titulo = $criarTituloAction->execute($data);

        return response()->json([
            'success' => true,
            'message' => 'Conta criada com sucesso.',
            'data' => $titulo,
        ], 201);
    }

    public function createContaPagar(Request $request, CriarDespesaAction $criarDespesaAction)
    {
        $data = $request->validate([
            'descricao' => 'required|string',
            'valor' => 'nullable|numeric',
            'valor_original' => 'nullable|numeric',
            'data_vencimento' => 'required|date',
            'data_emissao' => 'nullable|date',
            'fornecedor_id' => 'nullable|exists:fornecedores,id',
            'categoria_id' => 'nullable|exists:categorias_despesa,id',
            'documento_url' => 'nullable|string',
            'observacoes' => 'nullable|string',
            'codigo_barras' => 'nullable|string',
            'status' => 'nullable|in:pendente,pago,atrasado,cancelado',
            'plano_conta_id' => 'nullable|exists:planos_contas,id',
            'rateios' => 'nullable|array',
            'rateios.*.plano_conta_id' => 'required_with:rateios|exists:planos_contas,id',
            'rateios.*.centro_custo_id' => 'nullable|exists:centros_custo,id',
            'rateios.*.percentual' => 'nullable|numeric',
            'rateios.*.valor' => 'required_with:rateios|numeric',
        ]);

        $despesa = $criarDespesaAction->execute($data, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Conta a pagar criada com sucesso.',
            'data' => $despesa,
        ], 201);
    }
}
