<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FaturamentoController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'message' => 'FaturamentoController OK (stub)'
        ]);
    }
}
