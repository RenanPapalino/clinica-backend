#!/bin/bash
set -e

echo "🔧 Corrigindo tabela fatura_itens..."

cd /var/www/clinica-backend

# Pegar senha do banco
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

# Verificar estrutura atual
echo "1️⃣ Estrutura atual:"
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro -e "DESCRIBE fatura_itens;"

# Adicionar coluna item_numero se não existir
echo ""
echo "2️⃣ Adicionando coluna item_numero..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro << 'SQL'
ALTER TABLE fatura_itens 
ADD COLUMN IF NOT EXISTS item_numero INT DEFAULT 1 AFTER servico_id;
SQL

# Verificar se foi adicionada
echo ""
echo "3️⃣ Estrutura corrigida:"
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro -e "DESCRIBE fatura_itens;"

# Reiniciar servidor
echo ""
echo "4️⃣ Reiniciando servidor..."
killall php 2>/dev/null || true
php artisan serve --host=0.0.0.0 --port=8000 &
sleep 3

echo ""
echo "✅ Tabela fatura_itens corrigida!"
echo ""
echo "🧪 Teste novamente criar a fatura no Postman!"
