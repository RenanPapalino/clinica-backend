<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SendGeneratedCredentialsNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
        ]);

        $generatedPassword = Str::password(12);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $generatedPassword,
            'role' => 'user',
            'ativo' => true,
        ]);

        $emailSent = false;

        try {
            $user->notify(new SendGeneratedCredentialsNotification(
                login: $user->email,
                generatedPassword: $generatedPassword,
            ));
            $emailSent = true;
        } catch (\Throwable $e) {
            Log::error('REGISTER: Falha ao enviar credenciais por e-mail', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? 'Cadastro realizado e credenciais enviadas por e-mail.'
                : 'Cadastro realizado. Não foi possível enviar o e-mail automaticamente.',
            'user' => $this->serializeUser($user),
            'credentials' => [
                'login' => $user->email,
                'temporary_password' => $generatedPassword,
            ],
            'email_sent' => $emailSent,
        ], 201);
    }

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

        if (!$user->isAtivo()) {
            Log::warning('LOGIN: Usuário inativo', ['id' => $user->id]);

            return response()->json([
                'success' => false,
                'message' => 'Usuário inativo.',
            ], 403);
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
            'user'    => $this->serializeUser($user),
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

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink([
            'email' => mb_strtolower(trim($data['email'])),
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            Log::warning('FORGOT_PASSWORD: Falha ao gerar link', [
                'email' => $data['email'],
                'status' => $status,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Se o e-mail existir, você receberá instruções para redefinir a senha.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido ou expirado.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Senha redefinida com sucesso.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->serializeUser($request->user()));
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'ativo' => (bool) $user->ativo,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
