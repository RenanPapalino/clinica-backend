<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponseTrait;

    /**
     * Login do usuário
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Dados inválidos', 
                422, 
                $validator->errors()
            );
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Credenciais inválidas', 401);
        }

        // Criar token simples (sem JWT por enquanto)
        $token = base64_encode($user->id . '|' . now()->timestamp);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ], 'Login realizado com sucesso');
    }

    /**
     * Logout do usuário
     */
    public function logout(Request $request)
    {
        // Por enquanto apenas retorna sucesso
        // JWT invalidará o token aqui
        return $this->successResponse(null, 'Logout realizado com sucesso');
    }

    /**
     * Obter usuário autenticado
     */
    public function me(Request $request)
    {
        // Simular usuário por enquanto
        // Com JWT, será: $user = $request->user();
        
        $token = $request->bearerToken();
        
        if (!$token) {
            return $this->errorResponse('Token não fornecido', 401);
        }

        try {
            $decoded = base64_decode($token);
            list($userId, $timestamp) = explode('|', $decoded);
            
            $user = User::find($userId);
            
            if (!$user) {
                return $this->errorResponse('Usuário não encontrado', 404);
            }

            return $this->successResponse([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Token inválido', 401);
        }
    }

    /**
     * Registrar novo usuário
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Dados inválidos', 
                422, 
                $validator->errors()
            );
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = base64_encode($user->id . '|' . now()->timestamp);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ], 'Usuário criado com sucesso', 201);
    }
}
