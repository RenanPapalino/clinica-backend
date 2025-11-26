<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LancamentoContabilController extends Controller
{
    use ApiResponseTrait;

    /**
     * Lista os lançamentos do Livro Razão para a tela,
     * com filtros de período e conta contábil.
     */
    public function index(Request $request)
    {
        $query = $this->buildBaseQuery($request)
            ->with(['contaDebito', 'contaCredito'])
            ->orderBy('data_lancamento')
            ->orderBy('id');

        $lancamentos = $query->get();

        return $this->successResponse($lancamentos, 'Lançamentos carregados.');
    }

    /**
     * Balancete: calcula saldo (débito - crédito) por conta contábil,
     * no período informado.
     */
    public function balancete(Request $request)
    {
        $query = $this->buildBaseQuery($request);

        $lancamentos = $query->get();

        $saldos = [];

        foreach ($lancamentos as $l) {
            $valor = (float) $l->valor;

            if (!isset($saldos[$l->conta_debito_id])) {
                $saldos[$l->conta_debito_id] = 0;
            }
            if (!isset($saldos[$l->conta_credito_id])) {
                $saldos[$l->conta_credito_id] = 0;
            }

            // débito aumenta saldo, crédito diminui
            $saldos[$l->conta_debito_id]  += $valor;
            $saldos[$l->conta_credito_id] -= $valor;
        }

        $contas = PlanoConta::whereIn('id', array_keys($saldos))
            ->orderBy('codigo')
            ->get();

        $resultado = $contas->map(function (PlanoConta $conta) use ($saldos) {
            return [
                'conta_id'  => $conta->id,
                'codigo'    => $conta->codigo,
                'descricao' => $conta->descricao,
                'saldo'     => $saldos[$conta->id] ?? 0,
            ];
        });

        return $this->successResponse($resultado, 'Balancete gerado.');
    }

    /**
     * Roda auditoria com IA sobre os lançamentos no período.
     * Aqui é o ponto de integração com n8n / OpenAI.
     */
    public function auditarIa(Request $request)
    {
        $query = $this->buildBaseQuery($request)
            ->with(['contaDebito', 'contaCredito']);

        $lancamentos = $query->get();

        foreach ($lancamentos as $lanc) {
            // 🔹 Ponto real de IA:
            // você pode mandar esse lançamento para o n8n, que chama OpenAI,
            // retorna uma sugestão de conta débito/crédito, motivo, score etc.
            //
            // Para manter funcional sem dependência externa, faço um exemplo simples:
            $status   = 'manual';
            $score    = null;
            $sugestao = null;

            // Exemplo: valores acima de 1000 vão para "sugerido"
            if (abs((float) $lanc->valor) >= 1000) {
                $status = 'sugerido';
                $score  = 92.0;

                $sugestao = [
                    // se quiser, aqui podem ir IDs de contas sugeridas
                    'debito_id'        => $lanc->conta_debito_id,
                    'credito_id'       => $lanc->conta_credito_id,
                    'debito_codigo'    => $lanc->contaDebito?->codigo,
                    'debito_descricao' => $lanc->contaDebito?->descricao,
                    'credito_codigo'   => $lanc->contaCredito?->codigo,
                    'credito_descricao'=> $lanc->contaCredito?->descricao,
                    'motivo'           => 'Valor alto: recomenda-se revisão da classificação.',
                ];
            }

            $lanc->status_ia   = $status;
            $lanc->score_ia    = $score;
            $lanc->sugestao_ia = $sugestao;
            $lanc->save();
        }

        return $this->successResponse(null, 'Auditoria IA executada.');
    }

    /**
     * Aprova a sugestão da IA para um lançamento.
     * Se a sugestão tiver IDs de conta, atualiza a classificação.
     */
    public function aprovarIa(int $id)
    {
        $lanc = LancamentoContabil::with(['contaDebito', 'contaCredito'])
            ->findOrFail($id);

        if (!is_array($lanc->sugestao_ia)) {
            return $this->errorResponse(
                'Não há sugestão de IA para este lançamento.',
                422
            );
        }

        $sug = $lanc->sugestao_ia;

        // Se vierem IDs de contas da IA, atualiza
        if (!empty($sug['debito_id']) && !empty($sug['credito_id'])) {
            $lanc->conta_debito_id  = (int) $sug['debito_id'];
            $lanc->conta_credito_id = (int) $sug['credito_id'];
        }

        $lanc->status_ia = 'aprovado';
        $lanc->save();

        return $this->successResponse($lanc, 'Sugestão IA aprovada.');
    }

    /**
     * Marca um lançamento para revisão manual.
     */
    public function revisarIa(int $id)
    {
        $lanc = LancamentoContabil::findOrFail($id);
        $lanc->status_ia = 'revisar';
        $lanc->save();

        return $this->successResponse($lanc, 'Lançamento marcado para revisão.');
    }

    /**
     * Exporta o Livro Razão em formato OFX (para conciliação bancária).
     */
    public function exportOfx(Request $request): StreamedResponse
    {
        $query = $this->buildBaseQuery($request);

        $lancamentos = $query->orderBy('data_lancamento')->get();

        $headers = [
            'Content-Type'        => 'application/ofx',
            'Content-Disposition' => 'attachment; filename="livro-razao.ofx"',
        ];

        $callback = static function () use ($lancamentos) {
            $out  = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\n\n";
            $out .= "<OFX>\n  <BANKMSGSRSV1>\n    <STMTTRNRS>\n      <STMTRS>\n        <BANKTRANLIST>\n";

            foreach ($lancamentos as $l) {
                $data  = optional($l->data_lancamento)->format('Ymd');
                $valor = number_format((float) $l->valor, 2, '.', '');
                $desc  = substr($l->historico ?? 'Lancamento', 0, 80);

                $out .= "          <STMTTRN>\n";
                $out .= "            <DTPOSTED>{$data}\n";
                $out .= "            <TRNAMT>{$valor}\n";
                $out .= "            <MEMO>{$desc}\n";
                $out .= "          </STMTTRN>\n";
            }

            $out .= "        </BANKTRANLIST>\n      </STMTRS>\n    </STMTTRNRS>\n  </BANKMSGSRSV1>\n</OFX>\n";

            echo $out;
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Exporta Livro Razão em CSV (para Excel).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $query = $this->buildBaseQuery($request)
            ->with(['contaDebito', 'contaCredito']);

        $lancamentos = $query
            ->orderBy('data_lancamento')
            ->orderBy('id')
            ->get();

        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="livro-razao.csv"',
        ];

        $callback = static function () use ($lancamentos) {
            $handle = fopen('php://output', 'w');

            // Cabeçalho
            fputcsv(
                $handle,
                [
                    'Data',
                    'Historico',
                    'Conta Debito',
                    'Conta Credito',
                    'Valor',
                    'Status IA',
                ],
                ';'
            );

            foreach ($lancamentos as $l) {
                fputcsv(
                    $handle,
                    [
                        optional($l->data_lancamento)->format('d/m/Y'),
                        $l->historico,
                        $l->contaDebito?->codigo . ' - ' . $l->contaDebito?->descricao,
                        $l->contaCredito?->codigo . ' - ' . $l->contaCredito?->descricao,
                        number_format((float) $l->valor, 2, ',', '.'),
                        $l->status_ia,
                    ],
                    ';'
                );
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Query base reutilizada em index, balancete, IA e exportações.
     */
    private function buildBaseQuery(Request $request)
    {
        $query = LancamentoContabil::query();

        if ($ini = $request->input('inicio')) {
            $query->whereDate('data_lancamento', '>=', $ini);
        }

        if ($fim = $request->input('fim')) {
            $query->whereDate('data_lancamento', '<=', $fim);
        }

        if ($contaId = $request->input('conta_id')) {
            $query->where(function ($q) use ($contaId) {
                $q->where('conta_debito_id', $contaId)
                  ->orWhere('conta_credito_id', $contaId);
            });
        }

        return $query;
    }
}
