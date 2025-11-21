#!/bin/bash
set -e

echo "🎮 Criando TODOS os Controllers..."

cd /var/www/clinica-backend

mkdir -p app/Http/Controllers/Api

# ============================================
# SERVICO CONTROLLER
# ============================================
cat > app/Http/Controllers/Api/ServicoController.php << 'PHPEOF'
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Servico;
use Illuminate\Http\Request;

class ServicoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Servico::query();

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('categoria')) {
                $query->where('categoria', $request->categoria);
            }

            if ($request->has('search')) {
                $termo = $request->search;
                $query->where(function($q) use ($termo) {
                    $q->where('descricao', 'like', "%{$termo}%")
                      ->orWhere('codigo', 'like', "%{$termo}%");
                });
            }

            $servicos = $query->orderBy('descricao', 'asc')->get();

            return response()->json(['success' => true, 'data' => $servicos]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'codigo' => 'required|string|unique:servicos,codigo',
                'descricao' => 'required|string|max:200',
                'valor_unitario' => 'required|numeric|min:0',
                'categoria' => 'required|in:exame,consulta,procedimento,outros',
            ]);

            $servico = Servico::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Serviço cadastrado com sucesso',
                'data' => $servico
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $servico = Servico::findOrFail($id);
            return response()->json(['success' => true, 'data' => $servico]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Serviço não encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $servico = Servico::findOrFail($id);
            $servico->update($request->all());
            return response()->json(['success' => true, 'data' => $servico]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Servico::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Serviço excluído']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
PHPEOF

# ============================================
# NFSE CONTROLLER
# ============================================
cat > app/Http/Controllers/Api/NfseController.php << 'PHPEOF'
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nfse;
use App\Models\Fatura;
use Illuminate\Http\Request;

class NfseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Nfse::with(['fatura', 'cliente']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            $nfses = $query->orderBy('created_at', 'desc')->get();

            return response()->json(['success' => true, 'data' => $nfses]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function emitirLote(Request $request)
    {
        try {
            $validated = $request->validate([
                'fatura_ids' => 'required|array',
                'fatura_ids.*' => 'exists:faturas,id',
            ]);

            $nfses = [];
            
            foreach ($validated['fatura_ids'] as $faturaId) {
                $fatura = Fatura::find($faturaId);
                
                if (!$fatura->nfse_emitida) {
                    $nfse = Nfse::create([
                        'fatura_id' => $fatura->id,
                        'cliente_id' => $fatura->cliente_id,
                        'valor_servicos' => $fatura->valor_servicos,
                        'valor_iss' => $fatura->valor_servicos * 0.05,
                        'status' => 'pendente',
                    ]);
                    
                    $nfses[] = $nfse;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($nfses) . ' NFSe(s) criada(s)',
                'data' => $nfses
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function consultarProtocolo(Request $request)
    {
        try {
            $protocolo = $request->input('protocolo');
            $nfse = Nfse::where('protocolo', $protocolo)->first();

            if (!$nfse) {
                return response()->json(['success' => false, 'message' => 'Protocolo não encontrado'], 404);
            }

            return response()->json(['success' => true, 'data' => $nfse]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
PHPEOF

# ============================================
# TITULO CONTROLLER
# ============================================
cat > app/Http/Controllers/Api/TituloController.php << 'PHPEOF'
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Titulo;
use Illuminate\Http\Request;

class TituloController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Titulo::with(['cliente', 'fatura']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('cliente_id')) {
                $query->where('cliente_id', $request->cliente_id);
            }

            $titulos = $query->orderBy('data_vencimento', 'desc')->get();

            return response()->json(['success' => true, 'data' => $titulos]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'cliente_id' => 'required|exists:clientes,id',
                'fatura_id' => 'nullable|exists:faturas,id',
                'data_vencimento' => 'required|date',
                'valor_original' => 'required|numeric',
            ]);

            $titulo = Titulo::create([
                'cliente_id' => $validated['cliente_id'],
                'fatura_id' => $validated['fatura_id'] ?? null,
                'numero_titulo' => 'TIT-' . date('Ym') . '-' . str_pad((Titulo::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT),
                'data_emissao' => now(),
                'data_vencimento' => $validated['data_vencimento'],
                'valor_original' => $validated['valor_original'],
                'valor_saldo' => $validated['valor_original'],
                'status' => 'aberto',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Título criado com sucesso',
                'data' => $titulo
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $titulo = Titulo::with(['cliente', 'fatura'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $titulo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Título não encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $titulo = Titulo::findOrFail($id);
            $titulo->update($request->all());
            return response()->json(['success' => true, 'data' => $titulo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Titulo::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Título excluído']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function baixar(Request $request, $id)
    {
        try {
            $titulo = Titulo::findOrFail($id);
            
            $titulo->update([
                'status' => 'pago',
                'data_pagamento' => now(),
                'valor_saldo' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Título baixado com sucesso',
                'data' => $titulo
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function relatorioAging()
    {
        try {
            $titulos = Titulo::with('cliente')
                ->where('status', '!=', 'pago')
                ->orderBy('data_vencimento', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $titulos,
                'total' => $titulos->count()
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
PHPEOF

# ============================================
# RELATORIO CONTROLLER
# ============================================
cat > app/Http/Controllers/Api/RelatorioController.php << 'PHPEOF'
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fatura;
use App\Models\Titulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RelatorioController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $mesAtual = now()->format('Y-m');

            $data = [
                'faturamento_mes' => Fatura::where('periodo_referencia', $mesAtual)->sum('valor_total'),
                'a_receber_total' => Titulo::where('status', '!=', 'pago')->sum('valor_saldo'),
                'vencidos_total' => Titulo::where('status', 'vencido')->sum('valor_saldo'),
                'titulos_abertos' => Titulo::where('status', 'aberto')->count(),
                'nfse_emitidas_mes' => Fatura::where('periodo_referencia', $mesAtual)->where('nfse_emitida', true)->count(),
            ];

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function faturamentoPorPeriodo(Request $request)
    {
        try {
            $dataInicio = $request->input('data_inicio', now()->subMonths(6)->startOfMonth());
            $dataFim = $request->input('data_fim', now()->endOfMonth());

            $faturamento = Fatura::selectRaw('periodo_referencia, SUM(valor_total) as total')
                ->whereBetween('data_emissao', [$dataInicio, $dataFim])
                ->groupBy('periodo_referencia')
                ->orderBy('periodo_referencia', 'asc')
                ->get();

            return response()->json(['success' => true, 'data' => $faturamento]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function topClientes(Request $request)
    {
        try {
            $limite = $request->input('limite', 10);

            $clientes = Fatura::select('cliente_id', DB::raw('SUM(valor_total) as total_faturado'))
                ->with('cliente')
                ->groupBy('cliente_id')
                ->orderBy('total_faturado', 'desc')
                ->limit($limite)
                ->get();

            return response()->json(['success' => true, 'data' => $clientes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
PHPEOF

echo "✅ Todos os Controllers criados!"

# Listar controllers
ls -la app/Http/Controllers/Api/

# Limpar cache
php artisan optimize:clear

# Reiniciar servidor
killall php 2>/dev/null || true
nohup php artisan serve --host=0.0.0.0 --port=8000 > /var/log/laravel-api.log 2>&1 &
sleep 3

echo ""
echo "🧪 Testando rotas..."
php artisan route:list --path=api | head -40

echo ""
echo "✅ CONTROLLERS CRIADOS COM SUCESSO!"
