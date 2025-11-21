#!/bin/bash
set -e

echo "🔄 Recriando tabela fatura_itens..."

cd /var/www/clinica-backend

# Pegar senha do banco
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

# Backup dos dados existentes
echo "1️⃣ Fazendo backup..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro -e "SELECT * FROM fatura_itens;" > /tmp/fatura_itens_backup.txt 2>/dev/null || echo "Sem dados para backup"

# Recriar tabela
echo "2️⃣ Recriando tabela..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro << 'SQL'
DROP TABLE IF EXISTS fatura_itens;

CREATE TABLE fatura_itens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fatura_id BIGINT UNSIGNED NOT NULL,
    servico_id BIGINT UNSIGNED NULL,
    item_numero INT DEFAULT 1,
    descricao VARCHAR(200) NOT NULL,
    quantidade INT DEFAULT 1,
    valor_unitario DECIMAL(10,2) NOT NULL,
    valor_total DECIMAL(12,2) NOT NULL,
    data_realizacao DATE NULL,
    funcionario VARCHAR(100) NULL,
    matricula VARCHAR(50) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    FOREIGN KEY (fatura_id) REFERENCES faturas(id) ON DELETE CASCADE,
    FOREIGN KEY (servico_id) REFERENCES servicos(id),
    INDEX idx_fatura_id (fatura_id),
    INDEX idx_servico_id (servico_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

echo "3️⃣ Verificando estrutura:"
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro -e "DESCRIBE fatura_itens;"

echo ""
echo "✅ Tabela fatura_itens recriada com sucesso!"
echo ""
echo "🧪 Agora teste criar a fatura novamente no Postman!"
