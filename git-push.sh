#!/bin/bash
set -e

echo "📤 Fazendo commit e push para GitHub..."

cd /var/www/clinica-backend

# Inicializar Git (se ainda não foi)
if [ ! -d .git ]; then
    git init
    echo "✅ Git inicializado"
fi

# Adicionar todos os arquivos
git add .

# Ver o que será commitado
echo ""
echo "📋 Arquivos que serão commitados:"
git status --short

# Fazer commit
echo ""
read -p "Deseja continuar? (s/n): " confirmacao
if [ "$confirmacao" != "s" ]; then
    echo "❌ Cancelado"
    exit 1
fi

git commit -m "Initial commit - Sistema Financeiro Clínicas

- Estrutura Laravel 11
- Models: Cliente, Servico, Fatura, NFSe, Titulo
- Controllers CRUD completos
- Rotas API REST
- Integração N8N
- Migrations completas
- Seeders de exemplo
- Dockerfile para deploy
- Docker Compose para desenvolvimento
"

# Adicionar remote (substitua pela SUA URL do GitHub)
echo ""
echo "Cole a URL do seu repositório GitHub:"
echo "Exemplo: https://github.com/seu-usuario/clinica-backend.git"
read -p "URL: " REPO_URL

# Verificar se remote já existe
if git remote get-url origin 2>/dev/null; then
    echo "Remote 'origin' já existe, atualizando..."
    git remote set-url origin "$REPO_URL"
else
    git remote add origin "$REPO_URL"
fi

# Push para o GitHub
echo ""
echo "🚀 Fazendo push para GitHub..."
echo "⚠️  Você precisará autenticar (usar Personal Access Token se pedido)"
echo ""

git branch -M main
git push -u origin main

echo ""
echo "✅ Código enviado para o GitHub!"
echo ""
echo "🌐 Acesse: $REPO_URL"
echo ""

