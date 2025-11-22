<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Dados inválidos', 422, $validator->errors());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Credenciais inválidas', 401);
        }

        // CORREÇÃO: Gerar token real do Sanctum
        // Remove tokens antigos para manter apenas um ativo (opcional)
        $user->tokens()->delete(); 
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ], 'Login realizado com sucesso');
    }

    public function logout(Request $request)
    {
        // Revoga o token atual
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logout realizado com sucesso');
    }

    public function me(Request $request)
    {
        return $this->successResponse($request->user());
    }

    // NOVA FUNÇÃO: Recuperar Senha
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'channel' => 'in:email,whatsapp' // Opção de envio
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Email inválido', 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Retornamos sucesso mesmo se não existir para segurança (evitar enumeration)
            return $this->successResponse(null, 'Se o email existir, enviaremos as instruções.');
        }

        // Gera uma nova senha temporária (ou link de reset)
        $tempPassword = Str::random(8);
        $user->password = Hash::make($tempPassword);
        $user->save();

        // AQUI: Integração para enviar a senha
        // Em produção, dispare um Job ou chame o n8n para enviar
        $mensagem = "Olá {$user->name}, sua nova senha temporária no MDGestão é: {$tempPassword}";

        if ($request->channel === 'whatsapp' && $user->telefone) {
             // Exemplo: Http::post('n8n-webhook-whatsapp', ['phone' => $user->telefone, 'msg' => $mensagem]);
        } else {
             // Mail::to($user)->send(...);
        }

        // Para teste local, retornamos a senha (REMOVER EM PRODUÇÃO)
        return $this->successResponse([
            'debug_temp_password' => $tempPassword 
        ], 'Instruções enviadas (Verifique a resposta JSON para a senha temporária em ambiente dev).');
    }
}