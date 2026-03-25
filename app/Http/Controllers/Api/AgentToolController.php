<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cadastros\CriarClienteAction;
use App\Actions\Financeiro\BaixarDespesaAction;
use App\Actions\Financeiro\BaixarTituloAction;
use App\Actions\Financeiro\CriarDespesaAction;
use App\Actions\Financeiro\CriarFaturaManualAction;
use App\Actions\Financeiro\CriarTituloAction;
use App\Actions\Financeiro\RenegociarTituloAction;
use App\Actions\Rag\SearchRagChunksAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\FaturaResource;
use App\Http\Resources\NfseResource;
use App\Models\ChatMessage;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Despesa;
use App\Models\Fatura;
use App\Models\Fornecedor;
use App\Models\Nfse;
use App\Models\Titulo;
use App\Services\CnpjaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $data['user_context_keys'] = ['chat_user_' . $request->user()->id];

        return response()->json([
            'success' => true,
            'data' => $searchRagChunksAction->execute($data['query'], $data),
        ]);
    }

    public function consultarCnpj(Request $request, CnpjaService $cnpjaService)
    {
        $data = $request->validate([
            'cnpj' => ['required', 'string', 'min:14', 'max:25'],
        ]);

        $cnpj = preg_replace('/\D/', '', (string) $data['cnpj']);

        if (strlen($cnpj) !== 14 || !Cliente::isValidCnpj($cnpj)) {
            return response()->json([
                'success' => false,
                'message' => 'Informe um CNPJ válido para consulta.',
            ], 422);
        }

        try {
            $result = $cnpjaService->consultarCnpj($cnpj);
            $mapped = (array) ($result['mapped'] ?? []);
            $clienteExistente = Cliente::query()->where('cnpj', $cnpj)->first();
            $fornecedorExistente = Fornecedor::query()->where('cnpj', $cnpj)->first();

            return response()->json([
                'success' => true,
                'message' => 'Consulta CNPJ realizada com sucesso.',
                'data' => [
                    'cnpj' => $cnpj,
                    'cnpj_formatado' => $this->formatarCnpj($cnpj),
                    'provider' => $result['provider'] ?? 'cnpja',
                    'empresa' => [
                        'razao_social' => $mapped['razao_social'] ?? null,
                        'nome_fantasia' => $mapped['nome_fantasia'] ?? null,
                        'status' => $mapped['status'] ?? null,
                        'email' => $mapped['email'] ?? null,
                        'telefone' => $mapped['telefone'] ?? null,
                        'site' => $mapped['site'] ?? null,
                        'inscricao_estadual' => $mapped['inscricao_estadual'] ?? null,
                        'inscricao_municipal' => $mapped['inscricao_municipal'] ?? null,
                        'observacoes' => $mapped['observacoes'] ?? null,
                        'metadata' => $mapped['metadata'] ?? [],
                        'endereco' => [
                            'cep' => $mapped['cep'] ?? null,
                            'logradouro' => $mapped['logradouro'] ?? null,
                            'numero' => $mapped['numero'] ?? null,
                            'complemento' => $mapped['complemento'] ?? null,
                            'bairro' => $mapped['bairro'] ?? null,
                            'cidade' => $mapped['cidade'] ?? null,
                            'uf' => $mapped['uf'] ?? null,
                        ],
                    ],
                    'cliente_existente' => $clienteExistente ? [
                        'id' => $clienteExistente->id,
                        'cnpj' => $clienteExistente->cnpj,
                        'cnpj_formatado' => $clienteExistente->cnpj_formatado,
                        'razao_social' => $clienteExistente->razao_social,
                        'nome_fantasia' => $clienteExistente->nome_fantasia,
                        'status' => $clienteExistente->status,
                    ] : null,
                    'fornecedor_existente' => $fornecedorExistente ? [
                        'id' => $fornecedorExistente->id,
                        'cnpj' => $fornecedorExistente->cnpj,
                        'cnpj_formatado' => $this->formatarCnpj($fornecedorExistente->cnpj),
                        'razao_social' => $fornecedorExistente->razao_social,
                        'nome_fantasia' => $fornecedorExistente->nome_fantasia,
                        'status' => $fornecedorExistente->status,
                    ] : null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar CNPJá no runtime do agente', [
                'cnpj' => $cnpj,
                'message' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $status = str_contains(mb_strtolower($message), 'não foi possível') ? 502 : 422;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }
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

    public function faturamentoSummary(Request $request)
    {
        $data = $request->validate([
            'periodo_inicio' => 'required|date',
            'periodo_fim' => 'required|date|after_or_equal:periodo_inicio',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'status' => 'nullable|string|max:30',
            'nfse_emitida' => 'nullable|boolean',
        ]);

        $query = Fatura::query()
            ->whereDate('data_emissao', '>=', $data['periodo_inicio'])
            ->whereDate('data_emissao', '<=', $data['periodo_fim']);

        if (!empty($data['cliente_id'])) {
            $query->where('cliente_id', $data['cliente_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (array_key_exists('nfse_emitida', $data) && $data['nfse_emitida'] !== null) {
            $query->where('nfse_emitida', (bool) $data['nfse_emitida']);
        }

        $totalFaturado = (float) $query->sum('valor_total');
        $quantidadeFaturas = (int) $query->count();

        return response()->json([
            'success' => true,
            'data' => [
                'periodo_inicio' => $data['periodo_inicio'],
                'periodo_fim' => $data['periodo_fim'],
                'cliente_id' => $data['cliente_id'] ?? null,
                'status' => $data['status'] ?? null,
                'nfse_emitida' => $data['nfse_emitida'] ?? null,
                'total_faturado' => $totalFaturado,
                'quantidade_faturas' => $quantidadeFaturas,
                'ticket_medio' => $quantidadeFaturas > 0 ? round($totalFaturado / $quantidadeFaturas, 2) : 0.0,
            ],
        ]);
    }

    public function previsaoCaixa(Request $request)
    {
        $data = $request->validate([
            'periodo_inicio' => 'required|date',
            'periodo_fim' => 'required|date|after_or_equal:periodo_inicio',
        ]);

        $inicio = Carbon::parse($data['periodo_inicio'])->startOfDay();
        $fim = Carbon::parse($data['periodo_fim'])->endOfDay();

        $entradasPrevistas = (float) Titulo::query()
            ->where('tipo', 'receber')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereBetween('data_vencimento', [$inicio, $fim])
            ->sum('valor_saldo');

        $saidasPrevistas = (float) Despesa::query()
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereBetween('data_vencimento', [$inicio, $fim])
            ->sum(DB::raw('COALESCE(valor_original, valor)'));

        $recebimentosVencidos = (float) Titulo::query()
            ->where('tipo', 'receber')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', '<', $inicio->toDateString())
            ->sum('valor_saldo');

        $pagamentosVencidos = (float) Despesa::query()
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', '<', $inicio->toDateString())
            ->sum(DB::raw('COALESCE(valor_original, valor)'));

        return response()->json([
            'success' => true,
            'data' => [
                'periodo_inicio' => $inicio->toDateString(),
                'periodo_fim' => $fim->toDateString(),
                'entradas_previstas' => round($entradasPrevistas, 2),
                'saidas_previstas' => round($saidasPrevistas, 2),
                'saldo_previsto' => round($entradasPrevistas - $saidasPrevistas, 2),
                'recebimentos_vencidos_abertos' => round($recebimentosVencidos, 2),
                'pagamentos_vencidos_abertos' => round($pagamentosVencidos, 2),
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
            'query' => 'nullable|string|max:255',
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

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $documento = preg_replace('/\D/', '', $termo);

            $query->where(function ($builder) use ($termo, $documento) {
                $builder->where('descricao', 'like', "%{$termo}%")
                    ->orWhere('numero_titulo', 'like', "%{$termo}%")
                    ->orWhere('nosso_numero', 'like', "%{$termo}%")
                    ->orWhereHas('cliente', function ($clienteQuery) use ($termo, $documento) {
                        $clienteQuery->where('razao_social', 'like', "%{$termo}%")
                            ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                        if ($documento !== '') {
                            $clienteQuery->orWhere('cnpj', 'like', "%{$documento}%");
                        }
                    })
                    ->orWhereHas('fornecedor', function ($fornecedorQuery) use ($termo, $documento) {
                        $fornecedorQuery->where('razao_social', 'like', "%{$termo}%")
                            ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                        if ($documento !== '') {
                            $fornecedorQuery->orWhere('cnpj', 'like', "%{$documento}%")
                                ->orWhere('cpf', 'like', "%{$documento}%");
                        }
                    });
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit($data['limit'] ?? 20)->get(),
        ]);
    }

    public function searchFaturas(Request $request)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:255',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'status' => 'nullable|string|max:30',
            'periodo_inicio' => 'nullable|date',
            'periodo_fim' => 'nullable|date',
            'nfse_emitida' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Fatura::with(['cliente', 'itens'])
            ->withCount('itens')
            ->orderByDesc('data_emissao')
            ->orderByDesc('id');

        if (!empty($data['cliente_id'])) {
            $query->where('cliente_id', $data['cliente_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (array_key_exists('nfse_emitida', $data) && $data['nfse_emitida'] !== null) {
            $query->where('nfse_emitida', (bool) $data['nfse_emitida']);
        }

        if (!empty($data['periodo_inicio'])) {
            $query->whereDate('data_emissao', '>=', $data['periodo_inicio']);
        }

        if (!empty($data['periodo_fim'])) {
            $query->whereDate('data_emissao', '<=', $data['periodo_fim']);
        }

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $documento = preg_replace('/\D/', '', $termo);

            $query->where(function ($builder) use ($termo, $documento) {
                $builder->where('numero_fatura', 'like', "%{$termo}%")
                    ->orWhere('nfse_numero', 'like', "%{$termo}%")
                    ->orWhere('periodo_referencia', 'like', "%{$termo}%")
                    ->orWhereHas('cliente', function ($clienteQuery) use ($termo, $documento) {
                        $clienteQuery->where('razao_social', 'like', "%{$termo}%")
                            ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                        if ($documento !== '') {
                            $clienteQuery->orWhere('cnpj', 'like', "%{$documento}%");
                        }
                    });
            });
        }

        $faturas = $query->limit($data['limit'] ?? 20)->get();

        return response()->json([
            'success' => true,
            'data' => $faturas->map(fn (Fatura $fatura) => $this->formatFaturaSearchResult($fatura))->values(),
        ]);
    }

    public function searchNfse(Request $request)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:255',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'fatura_id' => 'nullable|integer|exists:faturas,id',
            'status' => 'nullable|string|max:30',
            'periodo_inicio' => 'nullable|date',
            'periodo_fim' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Nfse::with(['cliente', 'fatura'])
            ->orderByDesc('data_emissao')
            ->orderByDesc('id');

        if (!empty($data['cliente_id'])) {
            $query->where('cliente_id', $data['cliente_id']);
        }

        if (!empty($data['fatura_id'])) {
            $query->where('fatura_id', $data['fatura_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['periodo_inicio'])) {
            $query->whereDate('data_emissao', '>=', $data['periodo_inicio']);
        }

        if (!empty($data['periodo_fim'])) {
            $query->whereDate('data_emissao', '<=', $data['periodo_fim']);
        }

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $documento = preg_replace('/\D/', '', $termo);

            $query->where(function ($builder) use ($termo, $documento) {
                $builder->where('numero_nfse', 'like', "%{$termo}%")
                    ->orWhere('codigo_verificacao', 'like', "%{$termo}%")
                    ->orWhere('protocolo', 'like', "%{$termo}%")
                    ->orWhere('codigo_servico', 'like', "%{$termo}%")
                    ->orWhere('discriminacao', 'like', "%{$termo}%")
                    ->orWhereHas('fatura', function ($faturaQuery) use ($termo) {
                        $faturaQuery->where('numero_fatura', 'like', "%{$termo}%")
                            ->orWhere('periodo_referencia', 'like', "%{$termo}%");
                    })
                    ->orWhereHas('cliente', function ($clienteQuery) use ($termo, $documento) {
                        $clienteQuery->where('razao_social', 'like', "%{$termo}%")
                            ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                        if ($documento !== '') {
                            $clienteQuery->orWhere('cnpj', 'like', "%{$documento}%");
                        }
                    });
            });
        }

        return response()->json([
            'success' => true,
            'data' => NfseResource::collection($query->limit($data['limit'] ?? 20)->get())->resolve(),
        ]);
    }

    public function emitirNfse(Request $request)
    {
        $data = $request->validate([
            'fatura_id' => ['required', 'integer', 'exists:faturas,id'],
            'codigo_servico' => ['nullable', 'string', 'max:50'],
            'discriminacao' => ['nullable', 'string'],
        ]);

        $fatura = Fatura::with(['cliente', 'itens', 'titulos'])->findOrFail($data['fatura_id']);

        if ($fatura->nfse_emitida) {
            return response()->json([
                'success' => false,
                'message' => 'A fatura informada já possui NFS-e registrada.',
            ], 422);
        }

        $valorServicos = (float) ($fatura->valor_servicos ?? 0);
        if ($valorServicos <= 0) {
            $valorServicos = (float) $fatura->itens->sum(function ($item) {
                return (float) ($item->valor_total ?? ((float) $item->quantidade * (float) $item->valor_unitario));
            });
        }

        if ($valorServicos <= 0) {
            $valorServicos = (float) ($fatura->valor_total ?? 0);
        }

        $aliquotaIss = (float) ($fatura->cliente->aliquota_iss ?? 0);
        $valorIss = $aliquotaIss > 0 ? round($valorServicos * ($aliquotaIss / 100), 2) : (float) ($fatura->valor_iss ?? 0);
        $valorLiquido = max(round($valorServicos - $valorIss, 2), 0);

        $numeroGerado = 'NFSE-' . now()->format('YmdHis') . '-' . str_pad((string) $fatura->id, 4, '0', STR_PAD_LEFT);
        $protocolo = 'CHAT-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));

        $nfse = DB::transaction(function () use ($data, $fatura, $numeroGerado, $protocolo, $valorServicos, $valorIss, $aliquotaIss, $valorLiquido) {
            $nfse = Nfse::create([
                'fatura_id' => $fatura->id,
                'cliente_id' => $fatura->cliente_id,
                'numero_nfse' => $numeroGerado,
                'codigo_verificacao' => Str::upper(Str::random(8)),
                'protocolo' => $protocolo,
                'data_envio' => now(),
                'data_emissao' => now(),
                'data_autorizacao' => now(),
                'valor_servicos' => $valorServicos,
                'valor_deducoes' => 0,
                'valor_iss' => $valorIss,
                'aliquota_iss' => $aliquotaIss,
                'valor_liquido' => $valorLiquido,
                'status' => 'emitida',
                'codigo_servico' => $data['codigo_servico'] ?? null,
                'discriminacao' => $data['discriminacao'] ?? ($fatura->observacoes ?: 'NFS-e gerada via chatbot MedIntelligence.'),
                'xml_nfse' => null,
                'pdf_url' => null,
                'mensagem_erro' => null,
                'detalhes_erro' => null,
            ]);

            $fatura->update([
                'nfse_emitida' => true,
                'nfse_numero' => $numeroGerado,
                'status' => 'emitida',
            ]);

            return $nfse->fresh(['cliente', 'fatura']);
        });

        return response()->json([
            'success' => true,
            'message' => 'NFS-e registrada localmente com sucesso.',
            'data' => [
                'nfse' => (new NfseResource($nfse))->resolve(),
                'fatura' => (new FaturaResource($fatura->fresh(['cliente', 'itens', 'titulos'])))->resolve(),
            ],
        ]);
    }

    public function fechamentoDiario(Request $request)
    {
        $data = $request->validate([
            'data' => ['nullable', 'date'],
        ]);

        $referencia = isset($data['data'])
            ? Carbon::parse($data['data'])->startOfDay()
            : now()->startOfDay();
        $dataReferencia = $referencia->toDateString();

        $resumir = function ($query, string $campoValor): array {
            $base = clone $query;

            return [
                'quantidade' => (int) $base->count(),
                'valor_total' => round((float) $query->sum($campoValor), 2),
            ];
        };

        $recebimentosPrevistos = Titulo::query()
            ->where('tipo', 'receber')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', $dataReferencia);

        $pagamentosPrevistos = Despesa::query()
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', $dataReferencia);

        $recebimentosRealizados = Titulo::query()
            ->where('tipo', 'receber')
            ->whereDate('data_pagamento', $dataReferencia)
            ->where('valor_pago', '>', 0);

        $pagamentosRealizados = Despesa::query()
            ->whereDate('data_pagamento', $dataReferencia)
            ->where('valor_pago', '>', 0);

        $titulosVencidos = Titulo::query()
            ->where('tipo', 'receber')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', '<', $dataReferencia);

        $despesasVencidas = Despesa::query()
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', '<', $dataReferencia);

        $faturasPendentes = Fatura::query()
            ->whereIn('status', ['pendente', 'aberta', 'em_aberto']);

        $nfsePendentes = Nfse::query()
            ->where('status', 'pendente');

        $nfseComErro = Nfse::query()
            ->where('status', 'erro');

        $recebimentosRealizadosResumo = $resumir($recebimentosRealizados, 'valor_pago');
        $pagamentosRealizadosResumo = $resumir($pagamentosRealizados, 'valor_pago');

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $dataReferencia,
                'recebimentos_previstos_hoje' => $resumir($recebimentosPrevistos, 'valor_saldo'),
                'pagamentos_previstos_hoje' => $resumir($pagamentosPrevistos, 'valor'),
                'recebimentos_realizados_hoje' => $recebimentosRealizadosResumo,
                'pagamentos_realizados_hoje' => $pagamentosRealizadosResumo,
                'saldo_realizado_hoje' => round(
                    (float) $recebimentosRealizadosResumo['valor_total'] - (float) $pagamentosRealizadosResumo['valor_total'],
                    2
                ),
                'titulos_vencidos_abertos' => $resumir($titulosVencidos, 'valor_saldo'),
                'despesas_vencidas_abertas' => $resumir($despesasVencidas, 'valor'),
                'faturas_pendentes' => [
                    'quantidade' => (int) $faturasPendentes->count(),
                    'valor_total' => round((float) $faturasPendentes->sum('valor_total'), 2),
                ],
                'nfse_pendentes' => [
                    'quantidade' => (int) $nfsePendentes->count(),
                    'valor_total' => round((float) $nfsePendentes->sum('valor_liquido'), 2),
                ],
                'nfse_com_erro' => [
                    'quantidade' => (int) $nfseComErro->count(),
                    'valor_total' => round((float) $nfseComErro->sum('valor_liquido'), 2),
                ],
            ],
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

    public function baixarTitulo(Request $request, BaixarTituloAction $baixarTituloAction)
    {
        $data = $request->validate([
            'titulo_id' => ['required', 'exists:titulos,id'],
            'valor' => ['nullable', 'numeric', 'min:0.01'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'data_pagamento' => ['nullable', 'date'],
        ]);

        $titulo = Titulo::findOrFail($data['titulo_id']);
        $valor = isset($data['valor'])
            ? (float) $data['valor']
            : (float) ($titulo->valor_saldo ?? $titulo->valor_original);

        $titulo = $baixarTituloAction->execute(
            (int) $data['titulo_id'],
            $valor,
            $data['forma_pagamento'] ?? null,
            $data['data_pagamento'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Baixa do título registrada com sucesso.',
            'data' => $titulo->load(['cliente', 'fornecedor']),
        ]);
    }

    public function renegociarTitulo(Request $request, RenegociarTituloAction $renegociarTituloAction)
    {
        $data = $request->validate([
            'titulo_id' => ['required', 'exists:titulos,id'],
            'nova_data_vencimento' => ['required', 'date'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $titulo = $renegociarTituloAction->execute(
            (int) $data['titulo_id'],
            $data['nova_data_vencimento'],
            $data['observacoes'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Título renegociado com sucesso.',
            'data' => $titulo,
        ]);
    }

    public function baixarDespesa(Request $request, BaixarDespesaAction $baixarDespesaAction)
    {
        $data = $request->validate([
            'despesa_id' => ['required', 'exists:despesas,id'],
            'valor' => ['nullable', 'numeric', 'min:0.01'],
            'data_pagamento' => ['nullable', 'date'],
        ]);

        $despesa = $baixarDespesaAction->execute(
            (int) $data['despesa_id'],
            isset($data['valor']) ? (float) $data['valor'] : null,
            $data['data_pagamento'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Baixa da despesa registrada com sucesso.',
            'data' => $despesa,
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

    public function searchCobrancasInadimplentes(Request $request)
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'min_dias_atraso' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'valor_minimo' => ['nullable', 'numeric', 'min:0'],
            'canal' => ['nullable', 'in:whatsapp,email,manual'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $hoje = Carbon::today();
        $limit = $data['limit'] ?? 50;
        $query = Titulo::query()
            ->with('cliente')
            ->where('tipo', 'receber')
            ->whereNotIn('status', ['pago', 'cancelado'])
            ->whereDate('data_vencimento', '<', $hoje->toDateString());

        if (!empty($data['min_dias_atraso'])) {
            $query->whereDate('data_vencimento', '<=', $hoje->copy()->subDays((int) $data['min_dias_atraso'])->toDateString());
        }

        if (!empty($data['query'])) {
            $termo = $data['query'];
            $documento = preg_replace('/\D/', '', $termo);

            $query->whereHas('cliente', function ($clienteQuery) use ($termo, $documento) {
                $clienteQuery->where('razao_social', 'like', "%{$termo}%")
                    ->orWhere('nome_fantasia', 'like', "%{$termo}%");

                if ($documento !== '') {
                    $clienteQuery->orWhere('cnpj', 'like', "%{$documento}%");
                }
            });
        }

        $titulos = $query->get();
        $inadimplentes = [];

        foreach ($titulos as $titulo) {
            if (!$titulo->cliente) {
                continue;
            }

            $cliente = $titulo->cliente;
            $clienteId = (int) $cliente->id;
            $diasAtraso = max((int) $titulo->data_vencimento?->diffInDays($hoje, false) * -1, 0);

            if (!isset($inadimplentes[$clienteId])) {
                $canalSugerido = $cliente->celular || $cliente->telefone
                    ? 'whatsapp'
                    : ($cliente->email ? 'email' : 'manual');

                $inadimplentes[$clienteId] = [
                    'cliente_id' => $clienteId,
                    'razao_social' => $cliente->razao_social,
                    'nome_fantasia' => $cliente->nome_fantasia,
                    'cnpj' => $cliente->cnpj,
                    'email' => $cliente->email,
                    'telefone' => $cliente->telefone,
                    'celular' => $cliente->celular,
                    'canal_sugerido' => $canalSugerido,
                    'prioridade' => 'baixa',
                    'total_em_aberto' => 0.0,
                    'titulos_vencidos' => 0,
                    'dias_atraso' => 0,
                    'titulos' => [],
                ];
            }

            $inadimplentes[$clienteId]['total_em_aberto'] += (float) ($titulo->valor_saldo ?? 0);
            $inadimplentes[$clienteId]['titulos_vencidos']++;
            $inadimplentes[$clienteId]['dias_atraso'] = max($inadimplentes[$clienteId]['dias_atraso'], $diasAtraso);
            $inadimplentes[$clienteId]['titulos'][] = [
                'titulo_id' => $titulo->id,
                'numero_titulo' => $titulo->numero_titulo,
                'descricao' => $titulo->descricao,
                'data_vencimento' => $titulo->data_vencimento?->toDateString(),
                'dias_atraso' => $diasAtraso,
                'valor_saldo' => round((float) ($titulo->valor_saldo ?? 0), 2),
                'fatura_id' => $titulo->fatura_id,
                'cliente_id' => $titulo->cliente_id,
            ];
        }

        $clientes = array_values(array_map(function (array $item) use ($data) {
            $item['total_em_aberto'] = round((float) $item['total_em_aberto'], 2);
            $item['prioridade'] = $this->classificarPrioridadeCobranca(
                (float) $item['total_em_aberto'],
                (int) $item['dias_atraso'],
            );

            return $item;
        }, $inadimplentes));

        if (isset($data['valor_minimo'])) {
            $valorMinimo = (float) $data['valor_minimo'];
            $clientes = array_values(array_filter($clientes, fn (array $item) => (float) $item['total_em_aberto'] >= $valorMinimo));
        }

        if (!empty($data['canal'])) {
            $clientes = array_values(array_filter($clientes, fn (array $item) => ($item['canal_sugerido'] ?? null) === $data['canal']));
        }

        usort($clientes, function (array $a, array $b) {
            $peso = ['alta' => 3, 'media' => 2, 'baixa' => 1];

            return [$peso[$b['prioridade']] ?? 0, $b['dias_atraso'], $b['total_em_aberto']]
                <=> [$peso[$a['prioridade']] ?? 0, $a['dias_atraso'], $a['total_em_aberto']];
        });

        $clientes = array_slice($clientes, 0, $limit);
        $stats = [
            'total_clientes' => count($clientes),
            'total_inadimplencia' => round((float) array_sum(array_column($clientes, 'total_em_aberto')), 2),
            'titulos_vencidos' => (int) array_sum(array_column($clientes, 'titulos_vencidos')),
            'clientes_prioridade_alta' => count(array_filter($clientes, fn (array $item) => $item['prioridade'] === 'alta')),
            'clientes_com_whatsapp' => count(array_filter($clientes, fn (array $item) => $item['canal_sugerido'] === 'whatsapp')),
            'clientes_com_email' => count(array_filter($clientes, fn (array $item) => $item['canal_sugerido'] === 'email')),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'data_referencia' => $hoje->toDateString(),
                'clientes' => $clientes,
                'stats' => $stats,
            ],
        ]);
    }

    public function registrarCobrancaAutomacao(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'titulo_id' => ['nullable', 'exists:titulos,id'],
            'fatura_id' => ['nullable', 'exists:faturas,id'],
            'meio' => ['required', 'string', 'max:30'],
            'status' => ['required', 'in:pendente,enviada,falha,paga'],
            'canal' => ['required', 'string', 'max:50'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'valor_cobrado' => ['nullable', 'numeric', 'min:0'],
            'data_envio' => ['nullable', 'date'],
            'data_pagamento' => ['nullable', 'date'],
        ]);

        if (empty($data['titulo_id']) && !empty($data['fatura_id'])) {
            $data['titulo_id'] = Titulo::query()
                ->where('fatura_id', $data['fatura_id'])
                ->orderBy('id')
                ->value('id');
        }

        if (empty($data['data_envio']) && in_array($data['status'], ['enviada', 'falha'], true)) {
            $data['data_envio'] = now()->toDateTimeString();
        }

        $cobranca = Cobranca::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Evento de cobrança registrado com sucesso.',
            'data' => [
                'id' => $cobranca->id,
                'cliente_id' => $cobranca->cliente_id,
                'titulo_id' => $cobranca->titulo_id,
                'fatura_id' => $cobranca->fatura_id,
                'status' => $cobranca->status,
                'meio' => $cobranca->meio,
                'canal' => $cobranca->canal,
                'descricao' => $cobranca->descricao,
                'valor_cobrado' => (float) ($cobranca->valor_cobrado ?? 0),
                'data_envio' => $cobranca->data_envio?->toIso8601String(),
            ],
        ], 201);
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

    public function upsertCliente(Request $request, CriarClienteAction $criarClienteAction)
    {
        $data = $request->validate([
            'cliente_id' => 'nullable|integer',
            'dry_run' => 'nullable|boolean',
            'cnpj' => 'required|string',
            'razao_social' => 'required|string|max:200',
            'nome_fantasia' => 'nullable|string|max:200',
            'email' => 'nullable|string|max:100',
            'telefone' => 'nullable|string|max:20',
            'celular' => 'nullable|string|max:20',
            'cep' => 'nullable|string|max:20',
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'bairro' => 'nullable|string|max:100',
            'complemento' => 'nullable|string|max:100',
            'cidade' => 'nullable|string|max:100',
            'uf' => 'nullable|string|max:2',
            'status' => 'nullable|in:ativo,inativo',
            'observacoes' => 'nullable|string',
        ]);

        $payload = $this->normalizeClientePayload($data);
        [$clienteExistente, $matchedBy] = $this->findClienteForUpsert(
            $payload,
            isset($data['cliente_id']) ? (int) $data['cliente_id'] : null,
        );

        $preview = $this->buildClienteUpsertPreview($payload, $clienteExistente, $matchedBy);

        if ((bool) ($data['dry_run'] ?? false)) {
            return response()->json([
                'success' => true,
                'message' => 'Prévia de sincronização do cliente preparada com sucesso.',
                'data' => $preview,
            ]);
        }

        if ($clienteExistente) {
            $cliente = DB::transaction(function () use ($clienteExistente, $payload, $preview) {
                if ($clienteExistente->trashed()) {
                    $clienteExistente->restore();
                }

                if (!empty($preview['changed_fields'])) {
                    $clienteExistente->fill($this->adaptClientePayloadToCurrentSchema($payload));
                    $clienteExistente->save();
                }

                return $clienteExistente->fresh();
            });

            return response()->json([
                'success' => true,
                'message' => $preview['sync_operation'] === 'sem_alteracao'
                    ? 'Cliente já estava sincronizado.'
                    : 'Cliente atualizado com sucesso.',
                'data' => array_merge($preview, [
                    'cliente' => $cliente,
                ]),
            ]);
        }

        $cliente = $criarClienteAction->execute($payload);

        return response()->json([
            'success' => true,
            'message' => 'Cliente criado com sucesso.',
            'data' => array_merge($preview, [
                'sync_operation' => 'criar',
                'cliente_id' => $cliente->id,
                'cliente' => $cliente,
            ]),
        ], 201);
    }

    public function updateClienteStatus(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'status' => ['required', 'in:ativo,inativo'],
        ]);

        $cliente = Cliente::findOrFail($data['cliente_id']);
        $cliente->update([
            'status' => $data['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'inativo'
                ? 'Cliente inativado com sucesso.'
                : 'Cliente reativado com sucesso.',
            'data' => $cliente->fresh(),
        ]);
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

    public function createFatura(Request $request, CriarFaturaManualAction $criarFaturaManualAction)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'data_emissao' => ['nullable', 'date'],
            'data_vencimento' => ['required', 'date'],
            'periodo_referencia' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:20'],
            'observacoes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'gerar_titulo' => ['nullable', 'boolean'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.servico_id' => ['nullable', 'exists:servicos,id'],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0.01'],
        ]);

        $data['data_emissao'] = $data['data_emissao'] ?? now()->toDateString();
        $data['status'] = $data['status'] ?? 'pendente';
        $data['gerar_titulo'] = $data['gerar_titulo'] ?? true;

        $fatura = $criarFaturaManualAction->execute($data);

        return response()->json([
            'success' => true,
            'message' => 'Fatura criada com sucesso.',
            'data' => (new FaturaResource($fatura))->resolve(),
        ], 201);
    }

    private function normalizeClientePayload(array $data): array
    {
        $payload = [
            'cnpj' => preg_replace('/\D/', '', (string) ($data['cnpj'] ?? '')),
            'razao_social' => Str::upper(trim((string) ($data['razao_social'] ?? ''))),
            'nome_fantasia' => !empty($data['nome_fantasia']) ? Str::upper(trim((string) $data['nome_fantasia'])) : null,
            'email' => !empty($data['email']) ? Str::lower(trim((string) $data['email'])) : null,
            'telefone' => isset($data['telefone']) ? trim((string) $data['telefone']) : null,
            'celular' => isset($data['celular']) ? trim((string) $data['celular']) : null,
            'cep' => isset($data['cep']) ? trim((string) $data['cep']) : null,
            'logradouro' => isset($data['logradouro']) ? trim((string) $data['logradouro']) : null,
            'numero' => isset($data['numero']) ? trim((string) $data['numero']) : null,
            'bairro' => !empty($data['bairro']) ? Str::title(trim((string) $data['bairro'])) : null,
            'complemento' => isset($data['complemento']) ? trim((string) $data['complemento']) : null,
            'cidade' => !empty($data['cidade']) ? Str::title(trim((string) $data['cidade'])) : null,
            'uf' => !empty($data['uf']) ? Str::upper(trim((string) $data['uf'])) : null,
            'status' => $data['status'] ?? 'ativo',
            'observacoes' => isset($data['observacoes']) ? trim((string) $data['observacoes']) : null,
        ];

        return array_filter($payload, static fn ($value) => $value !== null && $value !== '');
    }

    private function adaptClientePayloadToCurrentSchema(array $payload): array
    {
        if (!Schema::hasTable('clientes')) {
            return $payload;
        }

        $colunas = array_flip(Schema::getColumnListing('clientes'));

        if (!isset($colunas['logradouro']) && isset($colunas['endereco']) && !empty($payload['logradouro'])) {
            $payload['endereco'] = $payload['logradouro'];
            unset($payload['logradouro']);
        }

        return array_intersect_key($payload, $colunas);
    }

    private function findClienteForUpsert(array $payload, ?int $clienteId = null): array
    {
        if ($clienteId) {
            $cliente = Cliente::withTrashed()->find($clienteId);
            if ($cliente) {
                return [$cliente, 'cliente_id'];
            }
        }

        if (!empty($payload['cnpj'])) {
            $cliente = Cliente::withTrashed()
                ->where('cnpj', $payload['cnpj'])
                ->first();

            if ($cliente) {
                return [$cliente, 'cnpj'];
            }
        }

        if (!empty($payload['razao_social'])) {
            $cliente = Cliente::withTrashed()
                ->whereRaw('upper(razao_social) = ?', [$payload['razao_social']])
                ->first();

            if ($cliente) {
                return [$cliente, 'razao_social'];
            }
        }

        return [null, null];
    }

    private function buildClienteUpsertPreview(array $payload, ?Cliente $clienteExistente, ?string $matchedBy): array
    {
        $snapshotAtual = $clienteExistente ? $this->clienteSnapshot($clienteExistente) : null;
        $changedFields = [];

        if ($snapshotAtual) {
            foreach ($payload as $field => $value) {
                if ($this->normalizeComparableClienteValue($field, $snapshotAtual[$field] ?? null)
                    !== $this->normalizeComparableClienteValue($field, $value)) {
                    $changedFields[] = $field;
                }
            }

            if ($clienteExistente->trashed()) {
                $changedFields[] = 'restaurar';
            }
        }

        $operation = $clienteExistente
            ? (empty($changedFields) ? 'sem_alteracao' : 'atualizar')
            : 'criar';

        return [
            'sync_operation' => $operation,
            'matched_by' => $matchedBy,
            'cliente_id' => $clienteExistente?->id,
            'cliente_label' => $payload['razao_social'] ?? $payload['nome_fantasia'] ?? $payload['cnpj'],
            'changed_fields' => array_values(array_unique($changedFields)),
            'cliente_proposto' => $payload,
            'cliente_atual' => $snapshotAtual,
        ];
    }

    private function clienteSnapshot(Cliente $cliente): array
    {
        return [
            'cnpj' => $cliente->cnpj,
            'razao_social' => $cliente->razao_social,
            'nome_fantasia' => $cliente->nome_fantasia,
            'email' => $cliente->email,
            'telefone' => $cliente->telefone,
            'celular' => $cliente->celular,
            'cep' => $cliente->cep,
            'logradouro' => $cliente->logradouro,
            'numero' => $cliente->numero,
            'bairro' => $cliente->bairro,
            'complemento' => $cliente->complemento,
            'cidade' => $cliente->cidade,
            'uf' => $cliente->uf,
            'status' => $cliente->status,
            'observacoes' => $cliente->observacoes,
        ];
    }

    private function normalizeComparableClienteValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'cnpj' => preg_replace('/\D/', '', (string) $value),
            'email' => Str::lower(trim((string) $value)),
            'razao_social', 'nome_fantasia', 'uf', 'status' => Str::upper(trim((string) $value)),
            'bairro', 'cidade' => Str::title(trim((string) $value)),
            default => trim((string) $value),
        };
    }

    private function classificarPrioridadeCobranca(float $valor, int $diasAtraso): string
    {
        if ($diasAtraso >= 30 || $valor >= 3000) {
            return 'alta';
        }

        if ($diasAtraso >= 10 || $valor >= 1000) {
            return 'media';
        }

        return 'baixa';
    }

    private function formatFaturaSearchResult(Fatura $fatura): array
    {
        $fatura->loadMissing(['cliente', 'itens', 'titulos']);
        $base = (new FaturaResource($fatura))->resolve();
        $metadata = is_array($base['metadata'] ?? null) ? $base['metadata'] : [];

        $funcionarios = collect($metadata['funcionarios'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $nome = trim((string) ($item['nome'] ?? ''));
                $setor = trim((string) ($item['setor'] ?? ''));
                $matricula = $item['matricula'] ?? null;

                if ($nome === '') {
                    return null;
                }

                $detalhes = [];
                if ($setor !== '') {
                    $detalhes[] = $setor;
                }
                if ($matricula !== null && $matricula !== '') {
                    $detalhes[] = 'matrícula ' . $matricula;
                }

                return empty($detalhes) ? $nome : $nome . ' (' . implode(', ', $detalhes) . ')';
            })
            ->filter()
            ->values();

        $exames = collect($metadata['exames'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $nome = trim((string) ($item['nome'] ?? ''));
                $quantidade = $item['quantidade'] ?? null;
                $valor = $item['valor_cobrar'] ?? null;

                if ($nome === '') {
                    return null;
                }

                $detalhes = [];
                if ($quantidade !== null && $quantidade !== '') {
                    $detalhes[] = (float) $quantidade == (int) $quantidade
                        ? (int) $quantidade . 'x'
                        : str_replace('.', ',', (string) $quantidade) . 'x';
                }
                if ($valor !== null && $valor !== '') {
                    $detalhes[] = 'R$ ' . number_format((float) $valor, 2, ',', '.');
                }

                return empty($detalhes) ? $nome : $nome . ' (' . implode(', ', $detalhes) . ')';
            })
            ->filter()
            ->values();

        $base['unidade_anexo'] = $metadata['unidade'] ?? null;
        $base['funcionarios_total'] = $metadata['numero_funcionarios']
            ?? $metadata['quantidade_funcionarios_registrados']
            ?? $funcionarios->count();
        $base['funcionarios_resumo'] = $funcionarios->take(15)->all();
        $base['exames_total'] = $metadata['quantidade_exames_registrados'] ?? $exames->count();
        $base['exames_resumo'] = $exames->take(20)->all();

        return $base;
    }

    private function formatarCnpj(?string $cnpj): ?string
    {
        $cnpj = preg_replace('/\D/', '', (string) $cnpj);

        if ($cnpj === '') {
            return null;
        }

        if (strlen($cnpj) !== 14) {
            return $cnpj;
        }

        return substr($cnpj, 0, 2) . '.'
            . substr($cnpj, 2, 3) . '.'
            . substr($cnpj, 5, 3) . '/'
            . substr($cnpj, 8, 4) . '-'
            . substr($cnpj, 12, 2);
    }
}
