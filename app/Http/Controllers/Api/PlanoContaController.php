<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\PlanoConta;

class PlanoContaController extends Controller {
    public function index() {
        // Retorna árvore ou lista plana. Aqui lista plana ordenada por código.
        return response()->json([
            'success' => true,
            'data' => PlanoConta::where('ativo', true)->orderBy('codigo')->get()
        ]);
    }
}