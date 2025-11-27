cat > app/Http/Controllers/Api/AuthController.php << 'PHP'
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
        // Log inicial pra garantir que entramos aqui
        Log::info('LOGIN DEBUG: entrou no AuthController@Api@login', [
            'email' => $request->input('email'),
        ]);

        // Valida entrada
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Procura usuário
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            Log::warning('LOGIN DEBUG: usuário não encontrado', [
                'email' => $data['email'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credenciais inválidas',
                'errors'  => null,
            ], 401);
        }

        // Verifica senha
        if (!Hash::check($data['password'], $user->password)) {
            Log::warning('LOGIN DEBUG: senha incorreta', [
                'email' => $data['email'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credenciais inválidas',
                'errors'  => null,
            ], 401);
        }

        Log::info('LOGIN DEBUG: usuário autenticado com sucesso', [
            'id'    => $user->id,
            'email' => $user->email,
        ]);

        // Se tiver Sanctum
        if (method_exists($user, 'createToken')) {
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            $token = $user->createToken('api')->plainTextToken;
        } else {
            // fallback simples
            $token = base64_encode($user->id . '|' . now());
        }

        return response()->json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'token'   => $token,
            'user'    => $user,
        ]);
    }
}
PHP
