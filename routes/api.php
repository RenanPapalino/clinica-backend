<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ServicoController;
use App\Http\Controllers\Api\FaturaController;
use App\Http\Controllers\Api\NfseController;
use App\Http\Controllers\Api\TituloController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\N8nController;

// ============================================
// HEALTH CHECK
// ============================================
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'Clinica Financeiro API',
        'version' => '1.0.0',
    ]);
});

// DB Test
Route::get('/db-test', function () {
    try {
        \DB::connection()->getPdo();
        return response()->json(['database' => 'connected']);
    } catch (\Exception $e) {
        return response()->json(['database' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// ============================================
// CADASTROS
// ============================================
Route::prefix('cadastros')->group(function () {
    // Clientes
    Route::apiResource('clientes', ClienteController::class);
    
    // Serviços
    Route::apiResource('servicos', ServicoController::class);
});

// ============================================
// FATURAMENTO
// ============================================
Route::prefix('faturamento')->group(function () {
    Route::get('faturas', [FaturaController::class, 'index']);
    Route::post('faturas', [FaturaController::class, 'store']);
    Route::get('faturas/{id}', [FaturaController::class, 'show']);
    Route::put('faturas/{id}', [FaturaController::class, 'update']);
    Route::delete('faturas/{id}', [FaturaController::class, 'destroy']);
    Route::post('faturas/{id}/itens', [FaturaController::class, 'adicionarItem']);
    Route::get('estatisticas', [FaturaController::class, 'estatisticas']);
});

// ============================================
// NFSE
// ============================================
Route::prefix('nfse')->group(function () {
    Route::get('/', [NfseController::class, 'index']);
    Route::post('/emitir-lote', [NfseController::class, 'emitirLote']);
    Route::get('/consultar-protocolo', [NfseController::class, 'consultarProtocolo']);
});

// ============================================
// CONTAS A RECEBER
// ============================================
Route::prefix('contas-receber')->group(function () {
    Route::apiResource('titulos', TituloController::class);
    Route::post('titulos/{id}/baixar', [TituloController::class, 'baixar']);
    Route::get('aging', [TituloController::class, 'relatorioAging']);
});

// ============================================
// RELATÓRIOS
// ============================================
Route::prefix('relatorios')->group(function () {
    Route::get('/dashboard', [RelatorioController::class, 'dashboard']);
    Route::get('/faturamento-periodo', [RelatorioController::class, 'faturamentoPorPeriodo']);
    Route::get('/top-clientes', [RelatorioController::class, 'topClientes']);
});

// ============================================
// N8N - WEBHOOKS E INTEGRAÇÕES
// ============================================
Route::prefix('n8n')->group(function () {
    Route::get('/buscar-cliente', [N8nController::class, 'buscarClientePorCnpj']);
    Route::get('/buscar-servico', [N8nController::class, 'buscarServicoPorCodigo']);
    Route::post('/processar-planilha-soc', [N8nController::class, 'processarPlanilhaSoc']);
    Route::get('/titulos-a-vencer', [N8nController::class, 'titulosAVencer']);
    Route::get('/titulos-vencidos', [N8nController::class, 'titulosVencidos']);
});
