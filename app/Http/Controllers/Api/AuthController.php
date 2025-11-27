<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        Log::info('LOGIN: Tentativa de acesso', ['email' => $request->input('email')]);

        // 1. Validação
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // 2. Buscar Usuário
        $user = User::where('email', $request->email)->first();

        // 3. Verificar Senha
        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::warning('LOGIN: Falha de credenciais', ['email' => $request->email]);
            
            return response()->json([
                'success' => false,
                'message' => 'E-mail ou senha incorretos.',
            ], 401);
        }

        // 4. Limpeza de tokens antigos (Opcional: mantém apenas um login ativo por vez)
        // Se quiser permitir múltiplos dispositivos, comente a linha abaixo.
        $user->tokens()->delete();

        // 5. Gerar Token "Opaco" (Salvo no banco, não é JWT)
        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('LOGIN: Sucesso', ['id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'token'   => $token,
            'user'    => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // Adicione outros campos se necessário, evite mandar tudo
            ]
        ]);
    }

    public function logout(Request $request)
    {
        // Revoga o token atual
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso.'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}