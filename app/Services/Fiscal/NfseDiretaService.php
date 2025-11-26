<?php

namespace App\Services\Fiscal;

use App\Models\Fatura;
use App\Models\Configuracao;
use App\Models\Nfse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Exception;

class NfseDiretaService
{
    protected $certPath;
    protected $certPass;
    protected $prefeituraUrl;

    public function __construct()
    {
        // Carrega configurações do banco de dados
        $this->certPath = Configuracao::where('chave', 'certificado_digital_path')->value('valor');
        $this->certPass = Configuracao::where('chave', 'certificado_senha')->value('valor');
        
        // URL de Homologação (Exemplo SP/ABRASF) - Deve vir do env ou banco
        $this->prefeituraUrl = env('PREFEITURA_WEBSERVICE_URL', 'https://nfe.prefeitura.sp.gov.br/ws/lote.asmx'); 
    }

    public function emitir(Fatura $fatura)
    {
        if (!$this->certPath || !Storage::exists($this->certPath)) {
            throw new Exception("Certificado digital não configurado ou não encontrado.");
        }

        // 1. Gerar XML do Lote RPS (Padrão ABRASF Simplificado)
        $xmlContent = $this->gerarXmlRps($fatura);
        
        // 2. Assinar XML
        $xmlAssinado = $this->assinarXml($xmlContent);

        // 3. Enviar via SOAP
        $retorno = $this->enviarSoap($xmlAssinado);

        // 4. Processar Retorno (Mockado para este exemplo inicial)
        // Em produção, você deve fazer o parse do XML de retorno da prefeitura
        $protocolo = 'PROT-' . time(); 
        
        // Salva o registro da NFSe
        $nfse = Nfse::create([
            'fatura_id' => $fatura->id,
            'numero' => null, // Será preenchido após processamento da prefeitura
            'codigo_verificacao' => $protocolo,
            'status' => 'processando', // Aguardando retorno assíncrono
            'valor_total' => $fatura->valor_total,
            'xml_url' => $this->salvarXml($xmlAssinado, $fatura->id),
            'data_emissao' => now()
        ]);

        return $nfse;
    }

    private function gerarXmlRps(Fatura $fatura)
    {
        // Exemplo básico de estrutura ABRASF. 
        // Cada prefeitura tem variações (Namespace, Tags específicas).
        
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElement('EnviarLoteRpsEnvio');
        $root->setAttribute('xmlns', 'http://www.abrasf.org.br/nfse.xsd');
        $dom->appendChild($root);

        $lote = $dom->createElement('LoteRps');
        $lote->setAttribute('Id', 'LOTE' . $fatura->id);
        $root->appendChild($lote);

        // Número do Lote
        $lote->appendChild($dom->createElement('NumeroLote', $fatura->id));
        $lote->appendChild($dom->createElement('Cnpj', preg_replace('/\D/', '', env('EMPRESA_CNPJ'))));
        $lote->appendChild($dom->createElement('InscricaoMunicipal', env('EMPRESA_IM')));
        $lote->appendChild($dom->createElement('QuantidadeRps', 1));

        $listaRps = $dom->createElement('ListaRps');
        $lote->appendChild($listaRps);

        $rps = $dom->createElement('Rps');
        $listaRps->appendChild($rps);

        // InfDeclaracaoPrestacaoServico
        $inf = $dom->createElement('InfDeclaracaoPrestacaoServico');
        $rps->appendChild($inf);

        // Dados do Serviço (Valores)
        $servico = $dom->createElement('Servico');
        $valores = $dom->createElement('Valores');
        $valores->appendChild($dom->createElement('ValorServicos', number_format($fatura->valor_total, 2, '.', '')));
        $valores->appendChild($dom->createElement('IssRetido', $fatura->iss_retido ? 1 : 2));
        $servico->appendChild($valores);
        
        $servico->appendChild($dom->createElement('ItemListaServico', '04.03')); // Exemplo Medicina
        $servico->appendChild($dom->createElement('Discriminacao', "Serviços médicos ref. Fatura #{$fatura->id}"));
        $inf->appendChild($servico);

        // Tomador
        $tomador = $dom->createElement('Tomador');
        $identificacaoTomador = $dom->createElement('IdentificacaoTomador');
        $cpfCnpj = $dom->createElement('CpfCnpj');
        $cpfCnpj->appendChild($dom->createElement('Cnpj', preg_replace('/\D/', '', $fatura->cliente->cnpj)));
        $identificacaoTomador->appendChild($cpfCnpj);
        $tomador->appendChild($identificacaoTomador);
        $tomador->appendChild($dom->createElement('RazaoSocial', $fatura->cliente->razao_social));
        $inf->appendChild($tomador);

        return $dom->saveXML();
    }

    private function assinarXml($xmlContent)
    {
        // Carrega o PFX
        $pfxContent = Storage::get($this->certPath);
        if (!openssl_pkcs12_read($pfxContent, $certs, $this->certPass)) {
            throw new Exception("Não foi possível ler o certificado PFX. Verifique a senha.");
        }

        $doc = new DOMDocument();
        $doc->loadXML($xmlContent);

        $objDSig = new XMLSecurityDSig();
        $objDSig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $objDSig->addReference(
            $doc,
            XMLSecurityDSig::SHA1,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true]
        );

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $objKey->loadKey($certs['pkey']);

        $objDSig->sign($objKey);
        $objDSig->add509Cert($certs['cert']);

        $objDSig->appendSignature($doc->documentElement);
        
        return $doc->saveXML();
    }

    private function enviarSoap($xmlAssinado)
    {
        // Configuração do cURL para envio com certificado cliente (mTLS se necessário)
        // Muitas prefeituras exigem que a conexão SSL seja feita usando o certificado da empresa
        
        $pfxContent = Storage::get($this->certPath);
        
        // Salva temporariamente o certificado e chave extraídos para o cURL usar
        // (O cURL não lê PFX direto da memória facilmente em todas as versões)
        openssl_pkcs12_read($pfxContent, $certData, $this->certPass);
        
        $pemFile = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($pemFile, $certData['cert'] . "\n" . $certData['pkey']);

        $headers = [
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Content-length: " . strlen($xmlAssinado),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->prefeituraUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlAssinado);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Autenticação SSL com o certificado
        curl_setopt($ch, CURLOPT_SSLCERT, $pemFile);
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPass);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        unlink($pemFile); // Limpa temp

        if ($err) {
            Log::error("Erro SOAP Prefeitura: $err");
            throw new Exception("Falha na comunicação com a Prefeitura: $err");
        }

        return $response;
    }

    private function salvarXml($xml, $faturaId)
    {
        $path = "xmls/nfse/lote_{$faturaId}_" . time() . ".xml";
        Storage::put($path, $xml);
        return $path;
    }
}