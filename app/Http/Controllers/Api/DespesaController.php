<?php

namespace App\Http\Controllers\Api;

use App\Actions\Financeiro\CriarDespesaAction;
use App\Http\Controllers\Controller;
use App\Models\Despesa;
use App\Models\Fornecedor;
use App\Services\CnpjaService;
use App\Services\Ai\DocumentReaderService;
use App\Services\Financeiro\BoletoBarcodeService;
use App\Services\Financeiro\NfeXmlImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DespesaController extends Controller
{
    public function index(Request $request)
    {
        $query = Despesa::with(['fornecedor', 'categoria', 'planoConta']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('inicio')) {
            $query->whereDate('data_vencimento', '>=', $request->inicio);
        }

        if ($request->filled('fim')) {
            $query->whereDate('data_vencimento', '<=', $request->fim);
        }

        $despesas = $query
            ->orderBy('data_vencimento')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $despesas,
        ]);
    }

    public function store(Request $request, CriarDespesaAction $criarDespesaAction)
    {
        $data = $request->validate([
            'descricao'        => 'required|string',
            'valor'            => 'nullable|numeric',
            'valor_original'   => 'nullable|numeric',
            'data_vencimento'  => 'required|date',
            'data_emissao'     => 'nullable|date',
            'fornecedor_id'    => 'nullable|exists:fornecedores,id',
            'categoria_id'     => 'nullable|exists:categorias_despesa,id',
            'documento_url'    => 'nullable|string',
            'documento_tipo'   => 'nullable|string|max:50',
            'observacoes'      => 'nullable|string',
            'codigo_barras'    => 'nullable|string',
            'status'           => 'nullable|in:pendente,pago,atrasado,cancelado',
            'plano_conta_id'   => 'nullable|exists:planos_contas,id',

            // rateios vindos do front (RateioForm)
            'rateios'                      => 'nullable|array',
            'rateios.*.plano_conta_id'     => 'required_with:rateios|exists:planos_contas,id',
            'rateios.*.centro_custo_id'    => 'nullable|exists:centros_custo,id',
            'rateios.*.percentual'         => 'nullable|numeric',
            'rateios.*.valor'              => 'required_with:rateios|numeric',
        ]);

        $despesa = $criarDespesaAction->execute($data, $request->user()->id ?? null);

        return response()->json([
            'success' => true,
            'data'    => $despesa->load(['fornecedor', 'categoria', 'planoConta']),
        ], 201);
    }

    public function analisarDocumento(
        Request $request,
        DocumentReaderService $ocrService,
        NfeXmlImportService $nfeXmlImportService,
        CnpjaService $cnpjaService
    ) {
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,xml']);

        $file = $request->file('file');
        $path = $file->store('temp_analise');
        $fullPath = storage_path('app/' . $path);

        try {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            if ($extension === 'xml') {
                $dados = $nfeXmlImportService->analisar(file_get_contents($fullPath));
                $origemAnalise = 'xml';
            } else {
                $jsonString = $ocrService->lerDocumento($fullPath);
                $dados = json_decode($jsonString, true);
                if (!is_array($dados)) {
                    throw new \RuntimeException('A análise do documento retornou um formato inválido.');
                }
                $origemAnalise = 'ocr';
            }

            $codigoBarras = preg_replace('/\D/', '', (string) ($dados['codigo_barras'] ?? '')) ?: null;
            $duplicado = false;
            $despesaExistente = null;
            if ($codigoBarras) {
                $despesaExistente = Despesa::with('fornecedor')
                    ->where('codigo_barras', $codigoBarras)
                    ->first();
                $duplicado = $despesaExistente !== null;
            }

            [$fornecedorExistente, $fornecedorSugerido] = $this->resolverFornecedorSugerido($dados, $cnpjaService);

            return response()->json([
                'success' => true,
                'origem_analise' => $origemAnalise,
                'dados_sugeridos' => [
                    'valor' => $dados['valor_total'] ?? null,
                    'data_vencimento' => $dados['data_vencimento'] ?? null,
                    'data_emissao' => $dados['data_emissao'] ?? null,
                    'descricao' => $dados['descricao'] ?? $file->getClientOriginalName(),
                    'codigo_barras' => $codigoBarras,
                    'fornecedor_id' => $fornecedorExistente?->id,
                    'nome_fornecedor' => $fornecedorExistente?->razao_social ?? ($fornecedorSugerido['razao_social'] ?? ($dados['nome_fornecedor'] ?? null)),
                    'documento_tipo' => $dados['documento_tipo'] ?? null,
                    'numero_documento' => $dados['numero_documento'] ?? null,
                    'observacoes' => $dados['observacoes'] ?? null,
                    'alerta_duplicidade' => $duplicado,
                ],
                'fornecedor_sugerido' => $fornecedorSugerido,
                'despesa_existente' => $despesaExistente ? [
                    'id' => $despesaExistente->id,
                    'descricao' => $despesaExistente->descricao,
                    'valor_original' => (float) ($despesaExistente->valor_original ?? $despesaExistente->valor),
                    'data_vencimento' => optional($despesaExistente->data_vencimento)->format('Y-m-d'),
                    'status' => $despesaExistente->status,
                    'fornecedor' => $despesaExistente->fornecedor ? $this->serializeFornecedor($despesaExistente->fornecedor) : null,
                ] : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        } finally {
            Storage::delete($path);
        }
    }

    public function analisarCodigoBarras(
        Request $request,
        BoletoBarcodeService $boletoBarcodeService
    ) {
        $validated = $request->validate([
            'codigo_barras' => 'required|string',
        ]);

        $analise = $boletoBarcodeService->analisar($validated['codigo_barras']);
        $codigoNormalizado = $analise['codigo_barras'] ?? preg_replace('/\D/', '', $validated['codigo_barras']);

        $despesaExistente = Despesa::with('fornecedor')
            ->where('codigo_barras', $codigoNormalizado)
            ->orWhere('codigo_barras', $analise['linha_digitavel'] ?? $codigoNormalizado)
            ->first();

        $fornecedorExistente = $despesaExistente?->fornecedor;

        return response()->json([
            'success' => true,
            'dados_sugeridos' => [
                'descricao' => $despesaExistente?->descricao ?: ($analise['descricao'] ?? 'Boleto'),
                'valor' => $analise['valor'] ?? ($despesaExistente?->valor_original ?? null),
                'data_vencimento' => $analise['data_vencimento'] ?? optional($despesaExistente?->data_vencimento)->format('Y-m-d'),
                'codigo_barras' => $codigoNormalizado,
                'fornecedor_id' => $fornecedorExistente?->id,
                'nome_fornecedor' => $fornecedorExistente?->razao_social,
                'alerta_duplicidade' => $despesaExistente !== null,
            ],
            'analise' => $analise,
            'fornecedor_sugerido' => $fornecedorExistente ? $this->serializeFornecedor($fornecedorExistente) : null,
            'despesa_existente' => $despesaExistente ? [
                'id' => $despesaExistente->id,
                'descricao' => $despesaExistente->descricao,
                'status' => $despesaExistente->status,
                'valor_original' => (float) ($despesaExistente->valor_original ?? $despesaExistente->valor),
            ] : null,
        ]);
    }

    public function pagar($id, Request $request)
    {
        $despesa = Despesa::findOrFail($id);

        $valorBaixa = $request->input('valor', 0);
        if ($valorBaixa <= 0) {
            $valorBaixa = $despesa->valor_original ?? $despesa->valor;
        }

        $despesa->update([
            'status'         => 'pago',
            'data_pagamento' => $request->input('data_pagamento', now()),
            'valor_pago'     => $valorBaixa,
        ]);

        // (Opcional) aqui futuramente você pode gerar o lançamento de baixa:
        // débito Fornecedores / crédito Bancos.

        return response()->json([
            'success' => true,
            'message' => 'Despesa paga',
        ]);
    }

    private function resolverFornecedorSugerido(array $dados, CnpjaService $cnpjaService): array
    {
        $fornecedorDocumento = is_array($dados['fornecedor'] ?? null) ? $dados['fornecedor'] : [];
        $cnpj = preg_replace('/\D/', '', (string) ($dados['cnpj_fornecedor'] ?? $fornecedorDocumento['cnpj'] ?? '')) ?: null;
        $nome = trim((string) ($dados['nome_fornecedor'] ?? $fornecedorDocumento['razao_social'] ?? ''));
        $payloadFornecedor = static fn (array $base) => array_filter($base, fn ($value) => $value !== null && $value !== '');

        if ($cnpj) {
            $fornecedor = Fornecedor::where('cnpj', $cnpj)->first();
            if ($fornecedor) {
                return [$fornecedor, $this->serializeFornecedor($fornecedor)];
            }

            try {
                $result = $cnpjaService->consultarCnpj($cnpj);
                return [null, $result['mapped']];
            } catch (\Throwable) {
                return [null, $payloadFornecedor([
                    'cnpj' => $cnpj,
                    'razao_social' => $nome ?: null,
                    'nome_fantasia' => $fornecedorDocumento['nome_fantasia'] ?? null,
                    'inscricao_estadual' => $fornecedorDocumento['inscricao_estadual'] ?? null,
                    'inscricao_municipal' => $fornecedorDocumento['inscricao_municipal'] ?? null,
                    'email' => $dados['email_fornecedor'] ?? $fornecedorDocumento['email'] ?? null,
                    'telefone' => $dados['telefone_fornecedor'] ?? $fornecedorDocumento['telefone'] ?? null,
                    'cep' => $dados['cep_fornecedor'] ?? $fornecedorDocumento['cep'] ?? null,
                    'logradouro' => $dados['logradouro_fornecedor'] ?? $fornecedorDocumento['logradouro'] ?? null,
                    'numero' => $dados['numero_fornecedor'] ?? $fornecedorDocumento['numero'] ?? null,
                    'complemento' => $dados['complemento_fornecedor'] ?? $fornecedorDocumento['complemento'] ?? null,
                    'bairro' => $dados['bairro_fornecedor'] ?? $fornecedorDocumento['bairro'] ?? null,
                    'cidade' => $dados['cidade_fornecedor'] ?? $fornecedorDocumento['cidade'] ?? null,
                    'uf' => $dados['uf_fornecedor'] ?? $fornecedorDocumento['uf'] ?? null,
                    'site' => $fornecedorDocumento['site'] ?? null,
                    'status' => 'ativo',
                ])];
            }
        }

        if ($nome !== '') {
            $fornecedor = Fornecedor::query()
                ->where('razao_social', 'like', '%' . $nome . '%')
                ->orWhere('nome_fantasia', 'like', '%' . $nome . '%')
                ->first();

            if ($fornecedor) {
                return [$fornecedor, $this->serializeFornecedor($fornecedor)];
            }

            return [null, $payloadFornecedor([
                'razao_social' => $nome,
                'nome_fantasia' => $fornecedorDocumento['nome_fantasia'] ?? null,
                'inscricao_estadual' => $fornecedorDocumento['inscricao_estadual'] ?? null,
                'inscricao_municipal' => $fornecedorDocumento['inscricao_municipal'] ?? null,
                'email' => $dados['email_fornecedor'] ?? $fornecedorDocumento['email'] ?? null,
                'telefone' => $dados['telefone_fornecedor'] ?? $fornecedorDocumento['telefone'] ?? null,
                'cep' => $dados['cep_fornecedor'] ?? $fornecedorDocumento['cep'] ?? null,
                'logradouro' => $dados['logradouro_fornecedor'] ?? $fornecedorDocumento['logradouro'] ?? null,
                'numero' => $dados['numero_fornecedor'] ?? $fornecedorDocumento['numero'] ?? null,
                'complemento' => $dados['complemento_fornecedor'] ?? $fornecedorDocumento['complemento'] ?? null,
                'bairro' => $dados['bairro_fornecedor'] ?? $fornecedorDocumento['bairro'] ?? null,
                'cidade' => $dados['cidade_fornecedor'] ?? $fornecedorDocumento['cidade'] ?? null,
                'uf' => $dados['uf_fornecedor'] ?? $fornecedorDocumento['uf'] ?? null,
                'site' => $fornecedorDocumento['site'] ?? null,
                'status' => 'ativo',
            ])];
        }

        return [null, null];
    }

    private function serializeFornecedor(Fornecedor $fornecedor): array
    {
        return [
            'id' => $fornecedor->id,
            'razao_social' => $fornecedor->razao_social,
            'nome_fantasia' => $fornecedor->nome_fantasia,
            'cnpj' => $fornecedor->cnpj,
            'cpf' => $fornecedor->cpf,
            'email' => $fornecedor->email,
            'telefone' => $fornecedor->telefone,
            'inscricao_estadual' => $fornecedor->inscricao_estadual,
            'inscricao_municipal' => $fornecedor->inscricao_municipal,
            'cep' => $fornecedor->cep,
            'logradouro' => $fornecedor->logradouro,
            'numero' => $fornecedor->numero,
            'complemento' => $fornecedor->complemento,
            'bairro' => $fornecedor->bairro,
            'cidade' => $fornecedor->cidade,
            'uf' => $fornecedor->uf,
            'site' => $fornecedor->site,
            'observacoes' => $fornecedor->observacoes,
            'status' => $fornecedor->status,
        ];
    }
}
