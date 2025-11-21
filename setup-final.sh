#!/bin/bash
set -e

echo "🚀 SETUP FINAL DO BACKEND..."

cd /var/www/clinica-backend

# Pegar senha
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

# 1. LIMPAR BANCO
echo "1️⃣ Limpando banco..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro << 'SQL'
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS users, password_reset_tokens, failed_jobs, sessions, cache, cache_locks, jobs, job_batches, migrations;
DROP TABLE IF EXISTS clientes, servicos, faturas, fatura_itens, nfse, nfse_lotes, titulos, titulo_baixas;
DROP TABLE IF EXISTS cobrancas, remessas_bancarias, plano_contas, centros_custo, configuracoes;
SET FOREIGN_KEY_CHECKS = 1;
SQL

# 2. LIMPAR MIGRATIONS
echo "2️⃣ Limpando migrations antigas..."
rm -f database/migrations/*_create_clientes_table.php
rm -f database/migrations/*_create_servicos_table.php
rm -f database/migrations/*_create_faturas*.php
rm -f database/migrations/*_create_fatura*.php
rm -f database/migrations/*_create_nfse*.php
rm -f database/migrations/*_create_titulo*.php
rm -f database/migrations/*_create_cobrancas*.php
rm -f database/migrations/*_create_remessas*.php
rm -f database/migrations/*_create_plano*.php
rm -f database/migrations/*_create_centros*.php
rm -f database/migrations/*_create_configuracoes*.php

# 3. RODAR MIGRATIONS
echo "3️⃣ Rodando migrations..."
php artisan migrate --force

# 4. POPULAR DADOS
echo "4️⃣ Populando dados de teste..."
php artisan db:seed --class=ServicoSeeder --force

# 5. LIMPAR CACHE
echo "5️⃣ Limpando cache..."
php artisan optimize:clear

# 6. REINICIAR SERVIDOR
echo "6️⃣ Reiniciando servidor..."
killall php 2>/dev/null || true
nohup php artisan serve --host=0.0.0.0 --port=8000 > /var/log/laravel-api.log 2>&1 &
sleep 3

# 7. TESTAR
echo ""
echo "🧪 TESTANDO API..."
echo ""
curl -s http://localhost:8000/api/health | jq || curl http://localhost:8000/api/health
echo ""
curl -s http://localhost:8000/api/cadastros/servicos | jq '.data | length' || curl http://localhost:8000/api/cadastros/servicos

echo ""
echo "✅ BACKEND PRONTO!"
echo ""
echo "🌐 Testar no Postman:"
echo "   http://72.61.39.94:8000/api/health"
echo "   http://72.61.39.94:8000/api/cadastros/servicos"
echo "   http://72.61.39.94:8000/api/cadastros/clientes"
echo ""
echo "📋 Ver rotas: php artisan route:list --path=api"
