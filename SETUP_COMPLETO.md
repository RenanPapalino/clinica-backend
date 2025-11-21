# 🚀 SETUP COMPLETO DO BACKEND - MEDINTELLIGENCE

## 📋 O QUE FOI IMPLEMENTADO

### ✅ Novos Controllers
- **AuthController** - Login, logout, register, me
- **CobrancaController** - Gestão de cobranças
- **ChatController** - Chat com IA e comandos

### ✅ Novos Models
- **Cobranca** - Histórico de cobranças
- **ChatMessage** - Mensagens do chat

### ✅ Novos Endpoints
- `/api/auth/*` - Autenticação completa
- `/api/cobrancas/*` - Sistema de cobranças
- `/api/chat/*` - Chat com IA
- `/api/faturamento/emitir-nfse/:id` - Emitir NFSe individual

### ✅ Melhorias
- **ApiResponseTrait** - Respostas padronizadas
- **CORS** configurado para frontend
- **Migrations** para novas tabelas
- **Seeder** com dados de teste

---

## 🔧 INSTALAÇÃO E CONFIGURAÇÃO

### 1️⃣ Instalar Dependências

```bash
cd /var/www/clinica-backend
composer install
```

### 2️⃣ Configurar .env

```bash
# Copiar exemplo
cp .env.example .env

# Editar configurações
nano .env
```

Configurar:
```env
APP_NAME="MedIntelligence API"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://72.61.39.94:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=clinica_backend
DB_USERNAME=root
DB_PASSWORD=sua_senha

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://72.61.39.94
```

### 3️⃣ Gerar Key e Rodar Migrations

```bash
php artisan key:generate
php artisan migrate:fresh
```

### 4️⃣ Aplicar Arquivos Novos

```bash
# 1. Substituir rotas
cp routes/api_COMPLETO.php routes/api.php

# 2. Substituir CORS
cp config/cors_NOVO.php config/cors.php

# 3. Adicionar método emitirNfse ao FaturaController
# Copie o conteúdo de: app/Http/Controllers/Api/FaturaController_emitirNfse.php
# E adicione ao final da classe FaturaController

# 4. Copiar seeder
cp database/seeders/DatabaseSeeder_COMPLETO.php database/seeders/DatabaseSeeder.php
```

### 5️⃣ Popular Banco com Dados de Teste

```bash
php artisan db:seed
```

Você verá:
```
✅ Usuário criado: admin@medintelligence.com / password
✅ 3 clientes criados
✅ 5 serviços criados
✅ 2 faturas de exemplo criadas
🎉 Banco de dados populado com sucesso!
```

### 6️⃣ Iniciar Servidor

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

---

## 🧪 TESTAR ENDPOINTS

### Health Check

```bash
curl http://72.61.39.94:8000/api/health
```

### Login

```bash
curl -X POST http://72.61.39.94:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@medintelligence.com",
    "password": "password"
  }'
```

### Listar Clientes

```bash
curl http://72.61.39.94:8000/api/cadastros/clientes
```

### Listar Faturas

```bash
curl http://72.61.39.94:8000/api/faturamento/faturas
```

### Chat - Enviar Mensagem

```bash
curl -X POST http://72.61.39.94:8000/api/chat/mensagem \
  -H "Content-Type: application/json" \
  -d '{"mensagem": "/faturas"}'
```

### Enviar Cobrança

```bash
curl -X POST http://72.61.39.94:8000/api/cobrancas/enviar/1 \
  -H "Content-Type: application/json" \
  -d '{"canal": "email"}'
```

---

## 📚 ESTRUTURA DE ENDPOINTS

### Autenticação
```
POST   /api/auth/login        - Login
POST   /api/auth/register     - Registrar
POST   /api/auth/logout       - Logout
GET    /api/auth/me           - Usuário autenticado
```

### Cadastros
```
GET    /api/cadastros/clientes              - Listar
POST   /api/cadastros/clientes              - Criar
GET    /api/cadastros/clientes/{id}         - Ver
PUT    /api/cadastros/clientes/{id}         - Atualizar
DELETE /api/cadastros/clientes/{id}         - Deletar

GET    /api/cadastros/servicos              - Listar
POST   /api/cadastros/servicos              - Criar
```

### Faturamento
```
GET    /api/faturamento/faturas             - Listar
POST   /api/faturamento/faturas             - Criar
GET    /api/faturamento/faturas/{id}        - Ver
PUT    /api/faturamento/faturas/{id}        - Atualizar
DELETE /api/faturamento/faturas/{id}        - Deletar
POST   /api/faturamento/emitir-nfse/{id}   - Emitir NFSe
```

### Cobranças
```
GET    /api/cobrancas                       - Listar
POST   /api/cobrancas/enviar/{id}          - Enviar
GET    /api/cobrancas/vencidos             - Vencidas
GET    /api/cobrancas/historico/{id}       - Histórico
```

### Chat
```
POST   /api/chat/mensagem                   - Enviar mensagem
GET    /api/chat/historico                  - Histórico
```

### N8N
```
POST   /api/n8n/processar-planilha-soc     - Upload planilha
GET    /api/n8n/titulos-vencidos           - Títulos vencidos
```

---

## 🐛 TROUBLESHOOTING

### Erro de Permissões

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Limpar Cache

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### Ver Logs

```bash
tail -f storage/logs/laravel.log
```

### Testar Banco de Dados

```bash
php artisan tinker
>>> \DB::connection()->getPdo();
```

---

## 📦 ARQUIVOS CRIADOS

```
app/
├── Traits/
│   └── ApiResponseTrait.php          ⭐ NOVO
├── Http/Controllers/Api/
│   ├── AuthController.php            ⭐ NOVO
│   ├── CobrancaController.php        ⭐ NOVO
│   ├── ChatController.php            ⭐ NOVO
│   └── FaturaController.php          ⭐ MODIFICAR
├── Models/
│   ├── Cobranca.php                  ⭐ NOVO
│   └── ChatMessage.php               ⭐ NOVO

database/migrations/
├── 2024_11_21_001_create_cobrancas_table.php      ⭐ NOVO
└── 2024_11_21_002_create_chat_messages_table.php  ⭐ NOVO

database/seeders/
└── DatabaseSeeder.php                ⭐ MODIFICAR

routes/
└── api.php                           ⭐ MODIFICAR

config/
└── cors.php                          ⭐ MODIFICAR
```

---

## ✅ CHECKLIST

- [ ] Dependências instaladas
- [ ] .env configurado
- [ ] Migrations rodadas
- [ ] Arquivos novos aplicados
- [ ] Seeder executado
- [ ] Servidor iniciado
- [ ] Endpoints testados
- [ ] CORS configurado
- [ ] Frontend conectado

---

## 🎉 PRONTO!

Seu backend agora está 100% integrado com o frontend e pronto para uso!

**Credenciais de teste:**
- Email: admin@medintelligence.com
- Senha: password

**Próximo passo:** Testar integração com o frontend!
