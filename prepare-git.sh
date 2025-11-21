#!/bin/bash
set -e

echo "🔧 Preparando código para Git..."

cd /var/www/clinica-backend

# 1. Criar .gitignore
cat > .gitignore << 'GITIGNORE'
# Laravel
/node_modules
/public/hot
/public/storage
/storage/*.key
/vendor
.env
.env.backup
.phpunit.result.cache
docker-compose.override.yml
Homestead.json
Homestead.yaml
npm-debug.log
yarn-error.log
/.idea
/.vscode

# Logs
/storage/logs/*.log

# OS
.DS_Store
Thumbs.db

# Backups
*.backup
*.bak
*.sql
*.zip

# Temporários
/tmp/
*.tmp
GITIGNORE

# 2. Criar .env.example (template sem senhas)
cat > .env.example << 'ENV'
APP_NAME="Clinica Financeiro API"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.papalino.com.br

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=clinica_financeiro
DB_USERNAME=clinica_user
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120
ENV

# 3. Criar README.md
cat > README.md << 'README'
# 🏥 Clínica Financeiro - API Backend

Sistema de gestão financeira para clínicas de saúde ocupacional.

## 📋 Funcionalidades

- ✅ Cadastro de Clientes
- ✅ Cadastro de Serviços
- ✅ Gestão de Faturas
- ✅ Emissão de NFSe
- ✅ Controle de Títulos (Contas a Receber)
- ✅ Relatórios Gerenciais
- ✅ Integração N8N para automações

## 🚀 Tecnologias

- **PHP 8.2**
- **Laravel 11**
- **MySQL 8.0**
- **Docker** (opcional)

## ⚙️ Instalação

### Requisitos
- PHP >= 8.2
- Composer
- MySQL >= 8.0

### Passo a Passo

1. Clone o repositório:
```bash
git clone https://github.com/SEU_USUARIO/clinica-backend.git
cd clinica-backend
```

2. Instale dependências:
```bash
composer install
```

3. Configure o ambiente:
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure o banco de dados no arquivo `.env`:
```env
DB_HOST=127.0.0.1
DB_DATABASE=clinica_financeiro
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```

5. Execute as migrations:
```bash
php artisan migrate
php artisan db:seed --class=ServicoSeeder
```

6. Inicie o servidor:
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

## 🌐 Endpoints da API

### Health Check
```
GET /api/health
```

### Cadastros
```
GET    /api/cadastros/clientes
POST   /api/cadastros/clientes
PUT    /api/cadastros/clientes/{id}
DELETE /api/cadastros/clientes/{id}

GET    /api/cadastros/servicos
POST   /api/cadastros/servicos
PUT    /api/cadastros/servicos/{id}
DELETE /api/cadastros/servicos/{id}
```

### Faturamento
```
GET    /api/faturamento/faturas
POST   /api/faturamento/faturas
GET    /api/faturamento/faturas/{id}
PUT    /api/faturamento/faturas/{id}
DELETE /api/faturamento/faturas/{id}
GET    /api/faturamento/estatisticas
```

### NFSe
```
GET    /api/nfse
POST   /api/nfse/emitir-lote
GET    /api/nfse/consultar-protocolo
```

### Integrações N8N
```
GET    /api/n8n/buscar-cliente?cnpj=12345678000199
GET    /api/n8n/buscar-servico?codigo=EXAM-001
POST   /api/n8n/processar-planilha-soc
GET    /api/n8n/titulos-vencidos
GET    /api/n8n/titulos-a-vencer?dias=7
```

## 📝 Licença

Proprietário - Papalino Sistemas

## 👨‍💻 Autor

Desenvolvido por Renan - papalino.com.br
README

# 4. Criar Dockerfile otimizado
cat > Dockerfile << 'DOCKERFILE'
FROM php:8.2-fpm-alpine

# Informações
LABEL maintainer="papalino.com.br"
LABEL description="API Laravel - Sistema Financeiro Clínicas"

# Instalar dependências do sistema
RUN apk add --no-cache \
    nginx \
    mysql-client \
    curl \
    zip \
    unzip \
    git \
    bash

# Instalar extensões PHP necessárias
RUN docker-php-ext-install pdo pdo_mysql

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /var/www

# Copiar arquivos do projeto
COPY . .

# Instalar dependências PHP (produção)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Configurar permissões
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# Expor porta
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8000/api/health || exit 1

# Comando de inicialização
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
DOCKERFILE

# 5. Criar .dockerignore
cat > .dockerignore << 'DOCKERIGNORE'
.git
.gitignore
.env
.env.backup
node_modules
vendor
storage/logs/*.log
*.sql
*.zip
*.backup
README.md
DOCKERIGNORE

# 6. Criar docker-compose.yml (opcional, para desenvolvimento local)
cat > docker-compose.yml << 'COMPOSE'
version: '3.8'

services:
  api:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: clinica-api
    restart: unless-stopped
    ports:
      - "8000:8000"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    volumes:
      - ./storage:/var/www/storage
    networks:
      - clinica-network

networks:
  clinica-network:
    driver: bridge
COMPOSE

echo ""
echo "✅ Arquivos Git preparados!"
echo ""
echo "📋 Arquivos criados:"
ls -la .gitignore .env.example README.md Dockerfile .dockerignore docker-compose.yml
