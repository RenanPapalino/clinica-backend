<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\CentroCusto;

class CentroCustoController extends Controller {
    public function index() {
        return response()->json([
            'success' => true,
            'data' => CentroCusto::where('ativo', true)->orderBy('nome')->get()
        ]);
    }
}