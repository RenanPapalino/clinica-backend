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
