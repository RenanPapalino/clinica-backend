<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\ChatMessage;
use App\Models\Fatura;
use App\Models\Cliente;
use App\Models\Cobranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    use ApiResponseTrait;

    /**
     * Enviar mensagem e processar comando
     */
    public function enviarMensagem(Request $request)
    {
        $mensagem = $request->input('mensagem');
        $userId = 1; // Por enquanto fixo, usar auth quando implementado
        $sessionId = $request->input('session_id', 'default');

        // Salvar mensagem do usuário
        ChatMessage::create([
            'user_id' => $userId,
            'role' => 'user',
            'content' => $mensagem,
            'session_id' => $sessionId,
        ]);

        // Processar comando
        $resposta = $this->processarComando($mensagem);

        // Salvar resposta do assistente
        $chatMessage = ChatMessage::create([
            'user_id' => $userId,
            'role' => 'assistant',
            'content' => $resposta,
            'session_id' => $sessionId,
        ]);

        return $this->successResponse([
            'id' => (string) $chatMessage->id,
            'role' => 'assistant',
            'content' => $resposta,
            'timestamp' => $chatMessage->created_at->toISOString(),
        ]);
    }

    /**
     * Histórico de mensagens
     */
    public function historico(Request $request)
    {
        $sessionId = $request->input('session_id', 'default');
        $userId = 1; // Por enquanto fixo

        $messages = ChatMessage::where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->take(50)
            ->get()
            ->map(function ($msg) {
                return [
                    'id' => (string) $msg->id,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'timestamp' => $msg->created_at->toISOString(),
                ];
            });

        return $this->successResponse($messages);
    }

    /**
     * Processar comando do chat
     */
    private function processarComando($mensagem)
    {
        $mensagem = trim(strtolower($mensagem));

        // FATURAS
        if (str_starts_with($mensagem, '/faturas')) {
            return $this->comandoFaturas($mensagem);
        }

        // COBRANÇAS
        if (str_starts_with($mensagem, '/cobrancas')) {
            return $this->comandoCobrancas($mensagem);
        }

        // CLIENTES
        if (str_starts_with($mensagem, '/clientes')) {
            return $this->comandoClientes($mensagem);
        }

        // NOVA FATURA
        if (str_starts_with($mensagem, '/nova fatura')) {
            return "Para criar uma nova fatura, acesse a página de Faturamento ou envie os dados no formato:\n\n/criar fatura CLIENTE_ID DATA_VENCIMENTO";
        }

        // AJUDA
        if (str_starts_with($mensagem, '/ajuda')) {
            return $this->comandoAjuda();
        }

        // CLIENTE POR CNPJ
        if (str_starts_with($mensagem, '/cliente ')) {
            $cnpj = trim(str_replace('/cliente ', '', $mensagem));
            return $this->comandoClientePorCnpj($cnpj);
        }

        // Resposta padrão
        return "Desculpe, não entendi o comando. Digite /ajuda para ver os comandos disponíveis.";
    }

    /**
     * Comando: /faturas
     */
    private function comandoFaturas($mensagem)
    {
        $status = null;

        if (str_contains($mensagem, 'pendentes')) {
            $status = 'pendente';
        } elseif (str_contains($mensagem, 'pagas')) {
            $status = 'paga';
        } elseif (str_contains($mensagem, 'vencidas')) {
            $status = 'vencida';
        }

        $query = Fatura::with('cliente');

        if ($status) {
            $query->where('status', $status);
        }

        $faturas = $query->orderBy('created_at', 'desc')->take(10)->get();

        if ($faturas->isEmpty()) {
            return "📊 Nenhuma fatura encontrada.";
        }

        $resposta = "📊 **Faturas" . ($status ? " - " . ucfirst($status) : "") . "** ({$faturas->count()})\n\n";

        foreach ($faturas as $fatura) {
            $resposta .= "━━━━━━━━━━━━━━━━\n";
            $resposta .= "🔢 {$fatura->numero_fatura}\n";
            $resposta .= "👤 {$fatura->cliente->razao_social}\n";
            $resposta .= "💰 R$ " . number_format($fatura->valor_total, 2, ',', '.') . "\n";
            $resposta .= "📅 Venc: " . $fatura->data_vencimento->format('d/m/Y') . "\n";
            $resposta .= "⚡ Status: " . strtoupper($fatura->status) . "\n\n";
        }

        return $resposta;
    }

    /**
     * Comando: /cobrancas
     */
    private function comandoCobrancas($mensagem)
    {
        if (str_contains($mensagem, 'vencidas')) {
            $vencidas = Fatura::with('cliente')
                ->where('status', 'pendente')
                ->whereDate('data_vencimento', '<', now())
                ->take(10)
                ->get();

            if ($vencidas->isEmpty()) {
                return "💰 Nenhuma cobrança vencida no momento!";
            }

            $resposta = "💰 **Cobranças Vencidas** ({$vencidas->count()})\n\n";

            foreach ($vencidas as $fatura) {
                $resposta .= "━━━━━━━━━━━━━━━━\n";
                $resposta .= "🔢 {$fatura->numero_fatura}\n";
                $resposta .= "👤 {$fatura->cliente->razao_social}\n";
                $resposta .= "💰 R$ " . number_format($fatura->valor_total, 2, ',', '.') . "\n";
                $resposta .= "📅 Venceu em: " . $fatura->data_vencimento->format('d/m/Y') . "\n\n";
            }

            return $resposta;
        }

        $cobrancas = Cobranca::with('fatura.cliente')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($cobrancas->isEmpty()) {
            return "💰 Nenhuma cobrança registrada.";
        }

        $resposta = "💰 **Histórico de Cobranças** ({$cobrancas->count()})\n\n";

        foreach ($cobrancas as $cobranca) {
            $resposta .= "━━━━━━━━━━━━━━━━\n";
            $resposta .= "🔢 Fatura: {$cobranca->fatura->numero_fatura}\n";
            $resposta .= "👤 {$cobranca->fatura->cliente->razao_social}\n";
            $resposta .= "📧 Canal: " . ucfirst($cobranca->canal) . "\n";
            $resposta .= "⚡ Status: " . ucfirst($cobranca->status) . "\n";
            $resposta .= "📅 Enviada em: " . $cobranca->data_envio->format('d/m/Y H:i') . "\n\n";
        }

        return $resposta;
    }

    /**
     * Comando: /clientes
     */
    private function comandoClientes($mensagem)
    {
        $status = str_contains($mensagem, 'ativos') ? 'ativo' : null;

        $query = Cliente::query();

        if ($status) {
            $query->where('status', $status);
        }

        $clientes = $query->take(10)->get();

        if ($clientes->isEmpty()) {
            return "👥 Nenhum cliente encontrado.";
        }

        $resposta = "👥 **Clientes** ({$clientes->count()})\n\n";

        foreach ($clientes as $cliente) {
            $resposta .= "━━━━━━━━━━━━━━━━\n";
            $resposta .= "🏢 {$cliente->razao_social}\n";
            $resposta .= "📄 CNPJ: {$cliente->cnpj}\n";
            $resposta .= "📧 {$cliente->email}\n";
            $resposta .= "⚡ Status: " . strtoupper($cliente->status) . "\n\n";
        }

        return $resposta;
    }

    /**
     * Comando: /cliente CNPJ
     */
    private function comandoClientePorCnpj($cnpj)
    {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);

        $cliente = Cliente::where('cnpj', 'like', "%{$cnpj}%")->first();

        if (!$cliente) {
            return "❌ Cliente não encontrado com CNPJ: {$cnpj}";
        }

        $resposta = "👤 **Detalhes do Cliente**\n\n";
        $resposta .= "━━━━━━━━━━━━━━━━\n";
        $resposta .= "🏢 {$cliente->razao_social}\n";
        $resposta .= "📄 CNPJ: {$cliente->cnpj}\n";
        $resposta .= "📧 Email: {$cliente->email}\n";
        $resposta .= "📞 Telefone: {$cliente->telefone}\n";
        $resposta .= "⚡ Status: " . strtoupper($cliente->status) . "\n";

        // Buscar faturas do cliente
        $faturas = Fatura::where('cliente_id', $cliente->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        if ($faturas->count() > 0) {
            $resposta .= "\n📊 **Últimas Faturas**\n";
            foreach ($faturas as $fatura) {
                $resposta .= "• {$fatura->numero_fatura} - R$ " . number_format($fatura->valor_total, 2, ',', '.') . " ({$fatura->status})\n";
            }
        }

        return $resposta;
    }

    /**
     * Comando: /ajuda
     */
    private function comandoAjuda()
    {
        return "🤖 **COMANDOS DISPONÍVEIS**\n\n" .
               "📊 **Faturamento:**\n" .
               "• /faturas - Listar faturas\n" .
               "• /faturas pendentes\n" .
               "• /faturas pagas\n" .
               "• /faturas vencidas\n\n" .
               "💰 **Cobranças:**\n" .
               "• /cobrancas - Listar cobranças\n" .
               "• /cobrancas vencidas\n\n" .
               "👥 **Clientes:**\n" .
               "• /clientes - Listar clientes\n" .
               "• /cliente CNPJ - Buscar por CNPJ\n\n" .
               "ℹ️ **Ajuda:**\n" .
               "• /ajuda - Esta mensagem\n";
    }
}
