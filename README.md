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
