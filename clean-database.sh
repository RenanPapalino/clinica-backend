#!/bin/bash
set -e

echo "🗑️  LIMPANDO BANCO DE DADOS COMPLETAMENTE..."

cd /var/www/clinica-backend

# Pegar senha do .env
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

echo "1️⃣ Apagando TODAS as tabelas..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro << 'SQL'
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS failed_jobs;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS cache;
DROP TABLE IF EXISTS cache_locks;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS job_batches;
DROP TABLE IF EXISTS migrations;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS servicos;
DROP TABLE IF EXISTS faturas;
DROP TABLE IF EXISTS fatura_itens;
DROP TABLE IF EXISTS nfse;
DROP TABLE IF EXISTS nfse_lotes;
DROP TABLE IF EXISTS titulos;
DROP TABLE IF EXISTS titulo_baixas;
DROP TABLE IF EXISTS cobrancas;
DROP TABLE IF EXISTS remessas_bancarias;
DROP TABLE IF EXISTS plano_contas;
DROP TABLE IF EXISTS centros_custo;
DROP TABLE IF EXISTS configuracoes;
SET FOREIGN_KEY_CHECKS = 1;
SQL

echo "2️⃣ Removendo TODAS as migrations (exceto padrões do Laravel)..."
cd database/migrations
rm -f *_create_clientes_table.php
rm -f *_create_servicos_table.php
rm -f *_create_faturas_table.php
rm -f *_create_fatura_itens_table.php
rm -f *_create_nfse*.php
rm -f *_create_titulos*.php
rm -f *_create_titulo_baixas*.php
rm -f *_create_cobrancas*.php
rm -f *_create_remessas*.php
rm -f *_create_plano_contas*.php
rm -f *_create_centros_custo*.php
rm -f *_create_configuracoes*.php
rm -f *_add_*.php

cd ../..

echo "3️⃣ Listando migrations restantes..."
ls -la database/migrations/

echo "✅ Limpeza completa!"
