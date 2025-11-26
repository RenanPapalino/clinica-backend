<?php

namespace App\Services\Bancos;

use App\Models\Titulo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class ItauService implements BancoInterface
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $certPath;
    protected $keyPath;
    protected $token;

    public function __construct()
    {
        // Em produção: https://api.itau.com.br/cash_management/v2
        // Em sandbox: https://api.itau.com.br/sandbox/cash_management/v2
        $this->baseUrl = env('ITAU_API_URL', 'https://api.itau.com.br/cash_management/v2');
        
        $this->clientId = env('ITAU_CLIENT_ID');
        $this->clientSecret = env('ITAU_CLIENT_SECRET');
        
        // Caminhos absolutos para os certificados
        $this->certPath = storage_path('app/certs/itau_cert.crt');
        $this->keyPath = storage_path('app/certs/itau_key.key');
        
        $this->validarCertificados();
    }

    private function validarCertificados()
    {
        if (!file_exists($this->certPath) || !file_exists($this->keyPath)) {
            // Em ambiente dev sem certificado, apenas loga o aviso para não travar tudo
            Log::warning("Certificados do Itaú não encontrados em: {$this->certPath}");
        }
    }

    public function autenticar()
    {
        if (!file_exists($this->certPath)) throw new Exception("Certificado CRT não encontrado.");

        try {
            $response = Http::withOptions([
                'cert' => $this->certPath,
                'ssl_key' => $this->keyPath,
            ])->asForm()->post('https://oauth.itau.com.br/identity/connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'readonly' // Ajuste o escopo conforme documentação do Itaú
            ]);

            if ($response->failed()) {
                throw new Exception("Erro Auth Itaú: " . $response->body());
            }

            $this->token = $response->json()['access_token'];
            return $this->token;

        } catch (\Exception $e) {
            Log::error("Falha autenticação Itaú: " . $e->getMessage());
            throw $e;
        }
    }

    public function registrarBoleto(Titulo $titulo)
    {
        // Garante token válido
        if (!$this->token) $this->autenticar();

        // Validações básicas
        if (!$titulo->cliente) throw new Exception("Título sem cliente vinculado.");
        
        // Formatação de dados conforme manual Itaú V2
        $payload = [
            'data_emissao' => $titulo->data_emissao->format('Y-m-d'),
            'data_vencimento' => $titulo->data_vencimento->format('Y-m-d'),
            'valor_nominal' => number_format($titulo->valor_original, 2, '.', ''),
            'seu_numero' => str_pad($titulo->id, 10, '0', STR_PAD_LEFT), // Nosso identificador
            'especie' => '01', // DM - Duplicata Mercantil
            'pagador' => [
                'nome' => mb_substr($titulo->cliente->razao_social, 0, 50),
                'cpf_cnpj' => preg_replace('/\D/', '', $titulo->cliente->cnpj),
                'logradouro' => mb_substr($titulo->cliente->logradouro, 0, 40),
                'bairro' => mb_substr($titulo->cliente->bairro, 0, 15),
                'cidade' => mb_substr($titulo->cliente->cidade, 0, 20),
                'uf' => $titulo->cliente->uf,
                'cep' => preg_replace('/\D/', '', $titulo->cliente->cep),
            ],
            // Juros e Multas (opcional, configurável)
            'juros' => [
                'codigo_tipo_juros' => 90, // Sem juros por padrão no registro (pode alterar)
            ],
            'multa' => [
                'codigo_tipo_multa' => 90, // Sem multa
            ]
        ];

        try {
            $response = Http::withToken($this->token)
                ->withOptions(['cert' => $this->certPath, 'ssl_key' => $this->keyPath])
                ->withHeaders([
                    'x-itau-apikey' => $this->clientId,
                    'x-itau-correlationID' => uniqid()
                ])
                ->post("{$this->baseUrl}/boletos", $payload);

            if ($response->failed()) {
                throw new Exception("Erro Registro Itaú: " . $response->body());
            }

            $dados = $response->json();
            
            // Retorna dados padronizados para salvar no banco
            return [
                'nosso_numero' => $dados['dados_individuais_boleto'][0]['nosso_numero'] ?? null,
                'codigo_barras' => $dados['dados_individuais_boleto'][0]['codigo_barras'] ?? null,
                'linha_digitavel' => $dados['dados_individuais_boleto'][0]['numero_linha_digitavel'] ?? null,
                'url_boleto' => null // Itaú API V2 geralmente não retorna URL pronta, tem que montar ou usar endpoint de PDF
            ];

        } catch (\Exception $e) {
            Log::error("Erro ao registrar boleto #{$titulo->id}: " . $e->getMessage());
            throw $e;
        }
    }

    public function consultarBoleto($nossoNumero)
    {
        // Implementação futura para atualização de status
    }

    public function gerarBoleto($titulo, $cliente) {
    // Lógica de chamada à API do Itaú...
    // ...
    return [
        'nosso_numero' => '123456',
        'link_pdf' => 'https://itau.com.br/boleto/pdf/xyz...',
        'linha_digitavel' => '34191.00000 00000.00000 ...'
    ];
}
}