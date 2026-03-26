<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use App\Models\User;
use App\Notifications\SendGeneratedCredentialsNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfiguracaoController extends Controller
{
    /**
     * Retorna dados da empresa
     */
    public function getEmpresa()
    {
        $config = Configuracao::where('chave', 'empresa')->first();
        
        if (!$config) {
            // Retorna valores padrão se não existir
            return response()->json([
                'razao_social' => '',
                'nome_fantasia' => '',
                'cnpj' => '',
                'inscricao_estadual' => '',
                'inscricao_municipal' => '',
                'endereco' => '',
                'cidade' => '',
                'estado' => '',
                'cep' => '',
                'telefone' => '',
                'email' => '',
                'website' => '',
                'logo_url' => ''
            ]);
        }

        return response()->json(json_decode($config->valor));
    }

    /**
     * Atualiza dados da empresa
     */
    public function updateEmpresa(Request $request)
    {
        $data = $request->validate([
            'razao_social' => 'required|string',
            'nome_fantasia' => 'nullable|string',
            'cnpj' => 'required|string',
            'inscricao_estadual' => 'nullable|string',
            'inscricao_municipal' => 'nullable|string',
            'endereco' => 'nullable|string',
            'cidade' => 'nullable|string',
            'estado' => 'nullable|string',
            'cep' => 'nullable|string',
            'telefone' => 'nullable|string',
            'email' => 'required|email',
            'website' => 'nullable|string',
            'logo_url' => 'nullable|string'
        ]);

        Configuracao::updateOrCreate(
            ['chave' => 'empresa'],
            ['valor' => json_encode($data)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Dados da empresa atualizados com sucesso'
        ]);
    }

    /**
     * Upload de logo
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg|max:2048'
        ]);

        // Remove logo anterior se existir
        $config = Configuracao::where('chave', 'empresa')->first();
        if ($config) {
            $empresaData = json_decode($config->valor, true);
            if (isset($empresaData['logo_url']) && Storage::disk('public')->exists($empresaData['logo_url'])) {
                Storage::disk('public')->delete($empresaData['logo_url']);
            }
        }

        // Upload novo logo
        $path = $request->file('logo')->store('logos', 'public');
        $url = asset('storage/' . $path);

        // Atualiza configuração
        if ($config) {
            $empresaData = json_decode($config->valor, true);
            $empresaData['logo_url'] = $url;
            $config->update(['valor' => json_encode($empresaData)]);
        }

        return response()->json([
            'success' => true,
            'logo_url' => $url
        ]);
    }

    /**
     * Retorna lista de usuários
     */
    public function getUsuarios()
    {
        $usuarios = User::select('id', 'name', 'email', 'role', 'ativo', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (User $usuario) => $this->serializeUser($usuario))
            ->values();

        return response()->json($usuarios);
    }

    /**
     * Cria novo usuário
     */
    public function storeUsuario(Request $request)
    {
        $data = $request->validate(
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'nullable|string|min:6',
                'role' => 'required|in:admin,user,viewer',
                'ativo' => 'nullable|boolean',
                'generate_password' => 'nullable|boolean',
                'send_credentials_email' => 'nullable|boolean',
            ],
            [
                'name.required' => 'Informe o nome completo do usuário.',
                'email.required' => 'Informe o e-mail do usuário.',
                'email.email' => 'Informe um e-mail válido.',
                'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
                'password.min' => 'A senha inicial deve ter pelo menos 6 caracteres.',
                'role.required' => 'Selecione o perfil do usuário.',
                'role.in' => 'Selecione um perfil válido para o usuário.',
            ]
        );

        $generatePassword = $data['generate_password'] ?? empty($data['password']);
        $sendCredentialsEmail = $data['send_credentials_email'] ?? true;
        $plainPassword = $generatePassword ? Str::password(12) : $data['password'];

        $data['email'] = mb_strtolower(trim($data['email']));
        unset($data['send_credentials_email']);
        unset($data['generate_password']);

        $data['password'] = Hash::make($plainPassword);
        $data['ativo'] = $data['ativo'] ?? true;

        $usuario = User::create($data);
        $emailSent = false;
        $emailError = null;

        if ($sendCredentialsEmail) {
            try {
                $usuario->notify(new SendGeneratedCredentialsNotification(
                    login: $usuario->email,
                    generatedPassword: $plainPassword,
                ));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::error('CONFIGURACOES: Falha ao enviar credenciais do usuário', [
                    'user_id' => $usuario->id,
                    'email' => $usuario->email,
                    'error' => $e->getMessage(),
                ]);
                $emailError = 'Nao foi possivel enviar o e-mail de acesso automaticamente.';
            }
        }

        $message = 'Usuário criado com sucesso.';

        if ($sendCredentialsEmail && $emailSent) {
            $message = 'Usuário criado e credenciais enviadas por e-mail.';
        } elseif ($sendCredentialsEmail && !$emailSent) {
            $message = 'Usuário criado, mas o envio do e-mail falhou. Use as credenciais exibidas em tela.';
        } elseif (!$sendCredentialsEmail) {
            $message = 'Usuário criado. Compartilhe as credenciais exibidas em tela com o usuário.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $emailSent,
            'credentials' => [
                'login' => $usuario->email,
                'password' => $plainPassword,
                'password_generated' => $generatePassword,
                'sent_by_email' => $sendCredentialsEmail,
            ],
            'email_error' => $emailError,
            'data' => $this->serializeUser($usuario->fresh()),
        ]);
    }

    /**
     * Atualiza usuário existente
     */
    public function updateUsuario(Request $request, $id)
    {
        $usuario = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|in:admin,user,viewer',
            'ativo' => 'sometimes|boolean'
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $usuario->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Usuário atualizado com sucesso',
            'data' => $this->serializeUser($usuario->fresh()),
        ]);
    }

    public function reenviarAcessoUsuario($id)
    {
        $usuario = User::findOrFail($id);

        $status = Password::sendResetLink([
            'email' => mb_strtolower(trim($usuario->email)),
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            Log::warning('CONFIGURACOES: Falha ao reenviar acesso', [
                'user_id' => $usuario->id,
                'email' => $usuario->email,
                'status' => $status,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível reenviar o acesso para este usuário.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Link de redefinição enviado com sucesso para o usuário.',
        ]);
    }

    /**
     * Exclui usuário
     */
    public function destroyUsuario($id)
    {
        $usuario = User::findOrFail($id);
        
        // Não permite excluir o próprio usuário logado
        if ($usuario->id === auth()->id()) {
            throw ValidationException::withMessages([
                'message' => 'Você não pode excluir sua própria conta'
            ]);
        }

        $usuario->delete();

        return response()->json([
            'success' => true,
            'message' => 'Usuário excluído com sucesso'
        ]);
    }

    private function serializeUser(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'email' => $usuario->email,
            'role' => $usuario->role,
            'ativo' => (bool) $usuario->ativo,
            'created_at' => $usuario->created_at?->toIso8601String(),
            'updated_at' => $usuario->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Retorna integrações configuradas
     */
    public function getIntegracoes()
    {
        $config = Configuracao::where('chave', 'integracoes')->first();
        
        if (!$config) {
            return response()->json([
                'n8n_webhook' => '',
                'whatsapp_token' => '',
                'email_smtp_host' => '',
                'email_smtp_port' => '',
                'email_smtp_user' => '',
                'email_smtp_password' => '',
                'banco_api_key' => '',
                'nfse_certificado_path' => '',
                'nfse_senha_certificado' => ''
            ]);
        }

        return response()->json(json_decode($config->valor));
    }

    /**
     * Atualiza integrações
     */
    public function updateIntegracoes(Request $request)
    {
        $data = $request->validate([
            'n8n_webhook' => 'nullable|string',
            'whatsapp_token' => 'nullable|string',
            'email_smtp_host' => 'nullable|string',
            'email_smtp_port' => 'nullable|string',
            'email_smtp_user' => 'nullable|string',
            'email_smtp_password' => 'nullable|string',
            'banco_api_key' => 'nullable|string',
            'nfse_certificado_path' => 'nullable|string',
            'nfse_senha_certificado' => 'nullable|string'
        ]);

        Configuracao::updateOrCreate(
            ['chave' => 'integracoes'],
            ['valor' => json_encode($data)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Integrações atualizadas com sucesso'
        ]);
    }

    /**
     * Retorna parâmetros fiscais
     */
    public function getFiscal()
    {
        $config = Configuracao::where('chave', 'fiscal')->first();
        
        if (!$config) {
            return response()->json([
                'aliquota_iss' => 2.00,
                'aliquota_pis' => 0.65,
                'aliquota_cofins' => 3.00,
                'aliquota_csll' => 1.00,
                'aliquota_irpj' => 1.50
            ]);
        }

        return response()->json(json_decode($config->valor));
    }

    /**
     * Atualiza parâmetros fiscais
     */
    public function updateFiscal(Request $request)
    {
        $data = $request->validate([
            'aliquota_iss' => 'required|numeric',
            'aliquota_pis' => 'required|numeric',
            'aliquota_cofins' => 'required|numeric',
            'aliquota_csll' => 'nullable|numeric',
            'aliquota_irpj' => 'nullable|numeric'
        ]);

        Configuracao::updateOrCreate(
            ['chave' => 'fiscal'],
            ['valor' => json_encode($data)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Parâmetros fiscais atualizados com sucesso'
        ]);
    }
}
