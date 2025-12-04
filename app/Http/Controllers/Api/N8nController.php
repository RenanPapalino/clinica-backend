<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Servico;
use App\Models\Fatura;
use App\Models\FaturaItem;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB; // <--- Importar DB

class N8nController extends Controller
{
    /**
     * Buscar cliente por CNPJ
     */
    public function buscarClientePorCnpj(Request $request)
    {
        $cnpj = $request->input('cnpj');
        
        if (!$cnpj) {
            return response()->json(['success' => false, 'message' => 'CNPJ não informado'], 400);
        }
        
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        $cliente = Cliente::where('cnpj', 'like', "%{$cnpj}%")->first();
        
        if (!$cliente) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado'], 404);
        }
        
        return response()->json(['success' => true, 'data' => $cliente]);
    }
    
    /**
     * Buscar serviço por código
     */
    public function buscarServicoPorCodigo(Request $request)
    {
        $codigo = $request->input('codigo');
        
        if (!$codigo) {
            return response()->json(['success' => false, 'message' => 'Código não informado'], 400);
        }
        
        $servico = Servico::where('codigo', $codigo)->first();
        
        if (!$servico) {
            return response()->json(['success' => false, 'message' => 'Serviço não encontrado'], 404);
        }
        
        return response()->json(['success' => true, 'data' => $servico]);
    }
    
    /**
     * Processar planilha SOC e criar fatura (COM TRANSAÇÃO)
     */
    public function processarPlanilhaSoc(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_cnpj' => 'required',
            'periodo' => 'required',
            'itens' => 'required|array',
            'itens.*.descricao' => 'required',
            'itens.*.quantidade' => 'required|integer',
            'itens.*.valor_unitario' => 'required|numeric',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'message' => 'Dados inválidos', 
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Início da Transação
        DB::beginTransaction();

        try {
            $cnpj = preg_replace('/[^0-9]/', '', $request->cliente_cnpj);
            $cliente = Cliente::where('cnpj', 'like', "%{$cnpj}%")->first();
            
            if (!$cliente) {
                throw new \Exception('Cliente não encontrado para o CNPJ: ' . $cnpj);
            }
            
            $valorTotal = 0;
            foreach ($request->itens as $item) {
                $valorTotal += $item['quantidade'] * $item['valor_unitario'];
            }
            
            $fatura = Fatura::create([
                'cliente_id' => $cliente->id,
                'numero_fatura' => $this->gerarNumeroFatura(),
                'data_emissao' => now(),
                'data_vencimento' => now()->addDays($cliente->prazo_pagamento_dias ?? 30),
                'periodo_referencia' => $request->periodo,
                'valor_servicos' => $valorTotal,
                'valor_total' => $valorTotal,
                'status' => 'emitida',
            ]);
            
            foreach ($request->itens as $index => $item) {
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
                ]);
            }

            // Commit: Salva tudo no banco se chegou até aqui
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Fatura criada com sucesso',
                'data' => $fatura->load('itens')
            ], 201);
            
        } catch (\Exception $e) {
            // Rollback: Desfaz tudo se deu erro
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar planilha: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // ... (Métodos titulosAVencer e titulosVencidos mantêm iguais) ...
    public function titulosAVencer(Request $request)
    {
        $dias = $request->input('dias', 7);
        $titulos = Titulo::with('cliente')
            ->where('status', 'aberto')
            ->whereBetween('data_vencimento', [now(), now()->addDays($dias)])
            ->orderBy('data_vencimento', 'asc')->get();
        return response()->json(['success' => true, 'data' => $titulos, 'total' => $titulos->count()]);
    }

    public function titulosVencidos()
    {
        $titulos = Titulo::with('cliente')
            ->where('status', 'aberto')
            ->where('data_vencimento', '<', now())
            ->orderBy('data_vencimento', 'asc')->get();
        return response()->json(['success' => true, 'data' => $titulos, 'total' => $titulos->count(), 'valor_total' => $titulos->sum('valor_saldo')]);
    }

    private function gerarNumeroFatura()
    {
        $ultimo = Fatura::max('id') ?? 0;
        return 'FAT-' . date('Ym') . '-' . str_pad($ultimo + 1, 6, '0', STR_PAD_LEFT);
    }
}
