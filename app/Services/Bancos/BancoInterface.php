<?php

namespace App\Services\Bancos;

use App\Models\Titulo;

interface BancoInterface
{
    /**
     * Realiza autenticação na API do banco (OAuth)
     */
    public function autenticar();

    /**
     * Envia os dados do título para registro
     * Retorna array com nosso_numero, codigo_barras e linha_digitavel
     */
    public function registrarBoleto(Titulo $titulo);
    
    /**
     * Consulta status atual do boleto
     */
    public function consultarBoleto($nossoNumero);
}