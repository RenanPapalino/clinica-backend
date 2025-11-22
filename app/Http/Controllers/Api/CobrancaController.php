<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Titulo;
use App\Models\Cliente;
use App\Models\Cobranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CobrancaController extends Controller
{
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
        
        // Busca títulos vencidos do cliente
        $titulos = Titulo::where('cliente_id', $clienteId)
            ->where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', Carbon::now())
            ->get();

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

            $response = Http::post($webhookUrl, [
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

            // Registra cobrança
            Cobranca::create([
                'cliente_id' => $cliente->id,
                'tipo' => 'whatsapp',
                'mensagem' => $mensagem,
                'status' => 'enviada',
                'data_envio' => now()
            ]);

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
        
        $titulos = Titulo::where('cliente_id', $clienteId)
            ->where('tipo', 'receber')
            ->where('status', '!=', 'pago')
            ->where('data_vencimento', '<', Carbon::now())
            ->get();

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

            $response = Http::post($webhookUrl, [
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

            // Registra cobrança
            Cobranca::create([
                'cliente_id' => $cliente->id,
                'tipo' => 'email',
                'mensagem' => 'E-mail de cobrança automático',
                'status' => 'enviada',
                'data_envio' => now()
            ]);

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
                
                if ($tipo === 'whatsapp') {
                    $this->enviarWhatsApp($fakeRequest, $data['cliente']->id);
                } else {
                    $this->enviarEmail($fakeRequest, $data['cliente']->id);
                }
                
                $enviados++;
                
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
            ->whereNull('numero_boleto')
            ->with('cliente')
            ->get();

        if ($titulos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum título disponível para remessa'
            ], 400);
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
            'arquivo' => 'required|file|mimes:ret,txt'
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
                    $titulo = Titulo::where('numero_boleto', $nossoNumero)->first();
                    
                    if ($titulo) {
                        $titulo->update([
                            'status' => 'pago',
                            'data_pagamento' => $dataPagamento,
                            'valor_pago' => $valorPago
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
        $linha = str_pad("P-{$titulo->id}", 240);
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
}
