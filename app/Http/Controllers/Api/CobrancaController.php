<?php

namespace App\Http\Controllers\Api;

use App\Actions\Financeiro\BaixarTituloAction;
use App\Http\Controllers\Controller;
use App\Models\Titulo;
use App\Models\Cliente;
use App\Models\Cobranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CobrancaController extends Controller
{
    public function __construct(
        private readonly BaixarTituloAction $baixarTituloAction,
    ) {
    }

    /**
     * Histórico e visão operacional de cobranças enviadas.
     */
    public function index(Request $request)
    {
        $query = $this->buildHistoricoQuery($request);
        $limit = max(1, min((int) $request->input('limit', 50), 200));

        $cobrancas = $query
            ->limit($limit)
            ->get()
            ->map(fn (Cobranca $cobranca) => $this->serializeCobranca($cobranca))
            ->values();

        $statsQuery = $this->buildHistoricoQuery($request);
        $stats = [
            'total_registros' => (clone $statsQuery)->count(),
            'enviadas' => (clone $statsQuery)->where('status', 'enviada')->count(),
            'pagas' => (clone $statsQuery)->where('status', 'paga')->count(),
            'falhas' => (clone $statsQuery)->where('status', 'falha')->count(),
            'valor_cobrado' => (float) ((clone $statsQuery)->sum('valor_cobrado') ?? 0),
        ];

        return response()->json([
            'success' => true,
            'data' => $cobrancas,
            'stats' => $stats,
        ]);
    }

    /**
     * Exporta relatório simples de cobranças.
     */
    public function relatorio(Request $request)
    {
        $query = $this->buildHistoricoQuery($request);
        $cobrancas = $query->get();
        $format = $request->input('format', 'csv');

        if ($format !== 'csv') {
            return response()->json([
                'success' => true,
                'data' => $cobrancas->map(fn (Cobranca $cobranca) => $this->serializeCobranca($cobranca)),
            ]);
        }

        $filename = 'relatorio_cobrancas_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($cobrancas) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id',
                'cliente',
                'meio',
                'canal',
                'status',
                'titulo',
                'fatura',
                'valor_cobrado',
                'data_envio',
                'data_pagamento',
                'descricao',
            ], ';');

            foreach ($cobrancas as $cobranca) {
                fputcsv($handle, [
                    $cobranca->id,
                    $cobranca->cliente?->razao_social,
                    $cobranca->meio,
                    $cobranca->canal,
                    $cobranca->status,
                    $cobranca->titulo?->numero_titulo ?? $cobranca->titulo_id,
                    $cobranca->fatura?->numero_fatura ?? $cobranca->fatura_id,
                    number_format((float) ($cobranca->valor_cobrado ?? 0), 2, ',', '.'),
                    $cobranca->data_envio?->format('d/m/Y H:i'),
                    $cobranca->data_pagamento?->format('d/m/Y H:i'),
                    $cobranca->descricao,
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    /**
     * Retorna clientes inadimplentes
     */
    public function inadimplentes()
    {
        $hoje = Carbon::now()->toDateString();
        
        // Busca títulos vencidos agrupados por cliente
        $titulosVencidos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', $hoje)
            ->with('cliente')
            ->get();

        $inadimplentesMap = [];
        
        foreach ($titulosVencidos as $titulo) {
            if (!$titulo->cliente) continue;
            
            $clienteId = $titulo->cliente->id;
            
            if (!isset($inadimplentesMap[$clienteId])) {
                $diasAtraso = Carbon::parse($titulo->data_vencimento)->diffInDays($hoje);
                
                $inadimplentesMap[$clienteId] = [
                    'id' => $titulo->cliente->id,
                    'razao_social' => $titulo->cliente->razao_social,
                    'cnpj' => $titulo->cliente->cnpj,
                    'email' => $titulo->cliente->email,
                    'telefone' => $titulo->cliente->telefone,
                    'total_em_aberto' => 0,
                    'dias_atraso' => $diasAtraso,
                    'titulos_vencidos' => 0
                ];
            }
            
            $inadimplentesMap[$clienteId]['total_em_aberto'] += floatval($titulo->valor_saldo);
            $inadimplentesMap[$clienteId]['titulos_vencidos']++;
            
            // Atualiza para maior dias de atraso
            $diasTitulo = Carbon::parse($titulo->data_vencimento)->diffInDays($hoje);
            if ($diasTitulo > $inadimplentesMap[$clienteId]['dias_atraso']) {
                $inadimplentesMap[$clienteId]['dias_atraso'] = $diasTitulo;
            }
        }

        // Converte para array e ordena por dias de atraso (maior primeiro)
        $inadimplentes = array_values($inadimplentesMap);
        usort($inadimplentes, function($a, $b) {
            return $b['dias_atraso'] - $a['dias_atraso'];
        });

        // Estatísticas
        $stats = [
            'total_inadimplencia' => array_sum(array_column($inadimplentes, 'total_em_aberto')),
            'clientes_inadimplentes' => count($inadimplentes),
            'titulos_vencidos' => array_sum(array_column($inadimplentes, 'titulos_vencidos')),
            'valor_medio_atraso' => count($inadimplentes) > 0 
                ? array_sum(array_column($inadimplentes, 'total_em_aberto')) / count($inadimplentes) 
                : 0
        ];

        return response()->json([
            'success' => true,
            'clientes' => $inadimplentes,
            'stats' => $stats
        ]);
    }

    /**
     * Envia cobrança via WhatsApp para um cliente específico
     */
    public function enviarWhatsApp(Request $request, $clienteId)
    {
        $cliente = Cliente::findOrFail($clienteId);
        $titulos = $this->buscarTitulosVencidosDoCliente((int) $clienteId);

        if ($titulos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente não possui títulos vencidos'
            ], 400);
        }

        $totalVencido = $titulos->sum('valor_saldo');
        
        // Mensagem personalizada ou padrão
        $mensagem = $request->input('mensagem') ?? $this->gerarMensagemPadrao($cliente, $titulos, $totalVencido);

        // Envia via N8N webhook
        try {
            $webhookUrl = env('N8N_COBRANCA_WHATSAPP_WEBHOOK');
            
            if (!$webhookUrl) {
                throw new \Exception('Webhook N8N não configurado');
            }

            $response = Http::timeout(15)->post($webhookUrl, [
                'cliente_id' => $cliente->id,
                'telefone' => $cliente->telefone,
                'mensagem' => $mensagem,
                'total_vencido' => $totalVencido,
                'titulos' => $titulos->map(function($t) {
                    return [
                        'numero' => $t->id,
                        'vencimento' => $t->data_vencimento,
                        'valor' => $t->valor_saldo
                    ];
                })
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Webhook N8N retornou erro para envio WhatsApp.');
            }

            $this->registrarCobrancasEnviadas($cliente, $titulos, 'whatsapp', $mensagem);

            return response()->json([
                'success' => true,
                'message' => 'Cobrança enviada via WhatsApp com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao enviar cobrança WhatsApp: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar cobrança: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envia cobrança via E-mail
     */
    public function enviarEmail(Request $request, $clienteId)
    {
        $cliente = Cliente::findOrFail($clienteId);
        $titulos = $this->buscarTitulosVencidosDoCliente((int) $clienteId);

        if ($titulos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente não possui títulos vencidos'
            ], 400);
        }

        $totalVencido = $titulos->sum('valor_saldo');

        try {
            $webhookUrl = env('N8N_COBRANCA_EMAIL_WEBHOOK');
            
            if (!$webhookUrl) {
                throw new \Exception('Webhook N8N não configurado');
            }

            $response = Http::timeout(15)->post($webhookUrl, [
                'cliente_id' => $cliente->id,
                'email' => $cliente->email,
                'razao_social' => $cliente->razao_social,
                'total_vencido' => $totalVencido,
                'titulos' => $titulos->map(function($t) {
                    return [
                        'numero' => $t->id,
                        'vencimento' => Carbon::parse($t->data_vencimento)->format('d/m/Y'),
                        'valor' => 'R$ ' . number_format($t->valor_saldo, 2, ',', '.')
                    ];
                })
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Webhook N8N retornou erro para envio de e-mail.');
            }

            $this->registrarCobrancasEnviadas($cliente, $titulos, 'email', 'E-mail de cobrança automático');

            return response()->json([
                'success' => true,
                'message' => 'Cobrança enviada via E-mail com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao enviar cobrança Email: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar cobrança: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envia cobranças em lote
     */
    public function enviarLote(Request $request)
    {
        $filtro = $request->input('filtro', 'todos'); // todos, critico, moderado
        $tipo = $request->input('tipo', 'whatsapp');
        
        $hoje = Carbon::now()->toDateString();
        
        // Busca inadimplentes conforme filtro
        $titulosVencidos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', $hoje)
            ->with('cliente')
            ->get();

        $clientesParaCobrar = [];
        
        foreach ($titulosVencidos as $titulo) {
            if (!$titulo->cliente) continue;
            
            $diasAtraso = Carbon::parse($titulo->data_vencimento)->diffInDays($hoje);
            
            // Aplica filtro
            if ($filtro === 'critico' && $diasAtraso < 30) continue;
            if ($filtro === 'moderado' && ($diasAtraso < 7 || $diasAtraso > 30)) continue;
            
            $clienteId = $titulo->cliente->id;
            
            if (!isset($clientesParaCobrar[$clienteId])) {
                $clientesParaCobrar[$clienteId] = [
                    'cliente' => $titulo->cliente,
                    'titulos' => []
                ];
            }
            
            $clientesParaCobrar[$clienteId]['titulos'][] = $titulo;
        }

        $enviados = 0;
        $erros = 0;

        foreach ($clientesParaCobrar as $data) {
            try {
                $fakeRequest = new Request(['mensagem' => null]);
                
                $response = $tipo === 'whatsapp'
                    ? $this->enviarWhatsApp($fakeRequest, $data['cliente']->id)
                    : $this->enviarEmail($fakeRequest, $data['cliente']->id);

                if ($response->getStatusCode() >= 400 || (($response->getData(true)['success'] ?? false) !== true)) {
                    throw new \RuntimeException($response->getData(true)['message'] ?? 'Falha no envio da cobrança.');
                } else {
                    $enviados++;
                }

                // Delay para não sobrecarregar
                usleep(500000); // 0.5 segundo
                
            } catch (\Exception $e) {
                $erros++;
                Log::error('Erro ao enviar cobrança em lote para cliente ' . $data['cliente']->id . ': ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Cobranças enviadas: $enviados | Erros: $erros",
            'enviados' => $enviados,
            'erros' => $erros
        ]);
    }

    /**
     * Gera arquivo de remessa bancária CNAB240
     */
    public function gerarRemessa()
    {
        // Busca títulos em aberto que ainda não têm boleto registrado
        $titulos = Titulo::where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where(function ($query) {
                $query->whereNull('nosso_numero')
                    ->orWhere('nosso_numero', '');
            })
            ->with('cliente')
            ->get();

        if ($titulos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum título disponível para remessa'
            ], 400);
        }

        $dataEnvio = now();

        foreach ($titulos as $titulo) {
            $this->prepararTituloParaRemessa($titulo);
        }

        // Gera arquivo CNAB240 (simplificado)
        $linhas = [];
        
        // Header do arquivo
        $linhas[] = $this->gerarHeaderArquivo();
        
        // Header do lote
        $linhas[] = $this->gerarHeaderLote();
        
        // Detalhes (cada título)
        foreach ($titulos as $titulo) {
            $linhas[] = $this->gerarSegmentoP($titulo);
            $linhas[] = $this->gerarSegmentoQ($titulo);
        }
        
        // Trailer do lote
        $linhas[] = $this->gerarTrailerLote(count($titulos));
        
        // Trailer do arquivo
        $linhas[] = $this->gerarTrailerArquivo(count($titulos));

        $conteudo = implode("\n", $linhas);
        
        // Salva arquivo
        $nomeArquivo = 'remessa_' . date('dmY_His') . '.rem';
        Storage::disk('public')->put('remessas/' . $nomeArquivo, $conteudo);
        $this->registrarCobrancasRemessa($titulos, $nomeArquivo, $dataEnvio);

        return response()->download(
            storage_path('app/public/remessas/' . $nomeArquivo),
            $nomeArquivo,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * Processa arquivo de retorno bancário
     */
    public function processarRetorno(Request $request)
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:ret,txt,cnab,rem'
        ]);

        $file = $request->file('arquivo');
        $conteudo = file_get_contents($file->getRealPath());
        $linhas = explode("\n", $conteudo);

        $processados = 0;
        $erros = 0;

        foreach ($linhas as $linha) {
            // Identifica tipo de registro (simplificado)
            $tipoRegistro = substr($linha, 7, 1);
            
            if ($tipoRegistro === '3') { // Segmento T (retorno)
                try {
                    $nossoNumero = trim(substr($linha, 37, 20));
                    $valorPago = floatval(substr($linha, 77, 13)) / 100;
                    $dataPagamento = $this->parseDataCNAB(substr($linha, 137, 8));
                    
                    // Busca título pelo nosso número
                    $titulo = Titulo::where('nosso_numero', $nossoNumero)->first();
                    
                    if ($titulo && $titulo->status !== 'pago') {
                        $titulo = $this->baixarTituloAction->execute(
                            $titulo->id,
                            $valorPago,
                            $titulo->forma_pagamento,
                            $dataPagamento,
                        );

                        Cobranca::where('titulo_id', $titulo->id)
                            ->whereIn('status', ['pendente', 'enviada'])
                            ->update([
                                'status' => 'paga',
                                'data_pagamento' => $titulo->data_pagamento,
                            ]);

                        $processados++;
                    }
                    
                } catch (\Exception $e) {
                    $erros++;
                    Log::error('Erro ao processar linha de retorno: ' . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Processados: $processados | Erros: $erros",
            'processados' => $processados,
            'erros' => $erros
        ]);
    }

    // ========== MÉTODOS AUXILIARES ==========

    private function gerarMensagemPadrao($cliente, $titulos, $totalVencido)
    {
        $mensagem = "Olá, {$cliente->razao_social}!\n\n";
        $mensagem .= "Identificamos que há pendências financeiras em sua conta:\n\n";
        
        foreach ($titulos as $titulo) {
            $vencimento = Carbon::parse($titulo->data_vencimento)->format('d/m/Y');
            $valor = 'R$ ' . number_format($titulo->valor_saldo, 2, ',', '.');
            $mensagem .= "📋 Título #{$titulo->id} - Venc: {$vencimento} - Valor: {$valor}\n";
        }
        
        $totalFormatado = 'R$ ' . number_format($totalVencido, 2, ',', '.');
        $mensagem .= "\n*Total em Aberto: {$totalFormatado}*\n\n";
        $mensagem .= "Por favor, regularize sua situação o quanto antes.\n";
        $mensagem .= "Em caso de dúvidas, entre em contato conosco.";
        
        return $mensagem;
    }

    private function gerarHeaderArquivo()
    {
        // CNAB240 - Header do Arquivo (simplificado)
        $linha = str_pad('341', 240); // Código banco Itaú (exemplo)
        return $linha;
    }

    private function gerarHeaderLote()
    {
        $linha = str_pad('HEADER LOTE', 240);
        return $linha;
    }

    private function gerarSegmentoP($titulo)
    {
        $linha = str_pad("P-{$titulo->nosso_numero}", 240);
        return $linha;
    }

    private function gerarSegmentoQ($titulo)
    {
        $linha = str_pad("Q-{$titulo->cliente->razao_social}", 240);
        return $linha;
    }

    private function gerarTrailerLote($qtd)
    {
        $linha = str_pad("TRAILER LOTE - QTD: $qtd", 240);
        return $linha;
    }

    private function gerarTrailerArquivo($qtd)
    {
        $linha = str_pad("TRAILER ARQUIVO - QTD: $qtd", 240);
        return $linha;
    }

    private function parseDataCNAB($dataStr)
    {
        // DDMMAAAA -> AAAA-MM-DD
        $dia = substr($dataStr, 0, 2);
        $mes = substr($dataStr, 2, 2);
        $ano = substr($dataStr, 4, 4);
        return "$ano-$mes-$dia";
    }

    private function buscarTitulosVencidosDoCliente(int $clienteId)
    {
        return Titulo::where('cliente_id', $clienteId)
            ->where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', Carbon::now())
            ->get();
    }

    private function buildHistoricoQuery(Request $request)
    {
        $query = Cobranca::query()
            ->with([
                'cliente:id,razao_social,email,telefone',
                'titulo:id,numero_titulo,data_vencimento',
                'fatura:id,numero_fatura',
            ])
            ->orderByDesc('data_envio')
            ->orderByDesc('id');

        if ($request->filled('meio')) {
            $query->where('meio', $request->input('meio'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->input('cliente_id'));
        }

        $query->periodoEnvio(
            $request->input('inicio'),
            $request->input('fim')
        );

        return $query;
    }

    private function serializeCobranca(Cobranca $cobranca): array
    {
        return [
            'id' => $cobranca->id,
            'cliente_id' => $cobranca->cliente_id,
            'cliente_nome' => $cobranca->cliente?->razao_social,
            'meio' => $cobranca->meio,
            'canal' => $cobranca->canal,
            'status' => $cobranca->status,
            'descricao' => $cobranca->descricao,
            'valor_cobrado' => (float) ($cobranca->valor_cobrado ?? 0),
            'data_envio' => $cobranca->data_envio?->toIso8601String(),
            'data_pagamento' => $cobranca->data_pagamento?->toIso8601String(),
            'titulo_id' => $cobranca->titulo_id,
            'titulo_numero' => $cobranca->titulo?->numero_titulo ?? $cobranca->titulo_id,
            'titulo_vencimento' => $cobranca->titulo?->data_vencimento?->toDateString(),
            'fatura_id' => $cobranca->fatura_id,
            'fatura_numero' => $cobranca->fatura?->numero_fatura ?? $cobranca->fatura_id,
        ];
    }

    private function prepararTituloParaRemessa(Titulo $titulo): void
    {
        if (!empty($titulo->nosso_numero)) {
            return;
        }

        $titulo->update([
            'nosso_numero' => $this->gerarNossoNumeroRemessa($titulo),
            'forma_pagamento' => $titulo->forma_pagamento ?? 'boleto',
            'status' => $titulo->status === 'pago' ? 'pago' : 'aberto',
        ]);
    }

    private function gerarNossoNumeroRemessa(Titulo $titulo): string
    {
        return sprintf(
            'RM%s%s',
            now()->format('ymd'),
            str_pad((string) $titulo->id, 10, '0', STR_PAD_LEFT)
        );
    }

    private function registrarCobrancasRemessa($titulos, string $nomeArquivo, Carbon $dataEnvio): void
    {
        foreach ($titulos as $titulo) {
            Cobranca::updateOrCreate(
                [
                    'titulo_id' => $titulo->id,
                    'meio' => 'boleto',
                    'canal' => 'remessa_cnab',
                    'status' => 'pendente',
                ],
                [
                    'cliente_id' => $titulo->cliente_id,
                    'fatura_id' => $titulo->fatura_id,
                    'descricao' => 'Remessa bancária gerada: ' . $nomeArquivo,
                    'data_envio' => $dataEnvio,
                    'valor_cobrado' => (float) ($titulo->valor_saldo ?? $titulo->valor_original),
                ]
            );
        }
    }

    private function registrarCobrancasEnviadas(Cliente $cliente, $titulos, string $meio, string $descricao): void
    {
        $dataEnvio = now();

        foreach ($titulos as $titulo) {
            Cobranca::create([
                'cliente_id' => $cliente->id,
                'fatura_id' => $titulo->fatura_id,
                'titulo_id' => $titulo->id,
                'meio' => $meio,
                'canal' => 'n8n',
                'descricao' => $descricao,
                'status' => 'enviada',
                'data_envio' => $dataEnvio,
                'valor_cobrado' => (float) ($titulo->valor_saldo ?? $titulo->valor_original),
            ]);
        }
    }
}
