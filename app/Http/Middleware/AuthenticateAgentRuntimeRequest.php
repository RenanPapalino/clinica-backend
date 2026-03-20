<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentRuntimeRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = (string) config('chatbot.runtime.secret');
        $providedSecret = (string) $request->header('X-Agent-Secret');

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Requisição interna do agente não autorizada.',
            ], 401);
        }

        $userId = $request->header('X-Agent-User-Id');
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Cabeçalho X-Agent-User-Id é obrigatório.',
            ], 422);
        }

        $user = User::ativos()->find($userId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário do agente não encontrado ou inativo.',
            ], 403);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
