<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateN8nIngestionRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = (string) config('chatbot.n8n.ingest_secret');
        $providedSecret = (string) $request->header('X-N8N-Secret');

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook de ingestão n8n não autorizado.',
            ], 401);
        }

        return $next($request);
    }
}
