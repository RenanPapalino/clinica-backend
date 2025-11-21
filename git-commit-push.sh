#!/bin/bash
set -e

echo "📤 Commit e Push para GitHub..."

cd /var/www/clinica-backend

# Verificar se Git está OK
if ! git status >/dev/null 2>&1; then
    echo "❌ Git ainda não está funcionando"
    echo "Execute: git config --global --add safe.directory /var/www/clinica-backend"
    exit 1
fi

# Inicializar se necessário
if [ ! -d .git ]; then
    git init -b main
    echo "✅ Repositório inicializado"
fi

# Adicionar todos os arquivos
echo "📁 Adicionando arquivos..."
git add .

# Ver o que será commitado
echo ""
echo "📋 Arquivos a serem commitados:"
git status --short

# Fazer commit
echo ""
git commit -m "Initial commit - Sistema Financeiro Clínicas

Features:
- ✅ CRUD Clientes
- ✅ CRUD Serviços  
- ✅ Gestão de Faturas
- ✅ Emissão NFSe
- ✅ Contas a Receber
- ✅ Integração N8N
- ✅ API REST completa
- ✅ Dockerfile para deploy
- ✅ Migrations e Seeders

Stack:
- Laravel 11
- PHP 8.2
- MySQL 8.0
- Docker
"

# Adicionar remote
echo ""
echo "🔗 Configurando remote do GitHub..."
echo ""
echo "Cole a URL do repositório GitHub:"
echo "Exemplo: https://github.com/RenanPapalino/clinica-backend.git"
echo ""
read -p "URL: " REPO_URL

# Verificar se URL é válida
if [[ ! "$REPO_URL" =~ ^https://github.com/.+/.+\.git$ ]]; then
    echo "⚠️  URL parece estar incompleta. Exemplo correto:"
    echo "https://github.com/RenanPapalino/clinica-backend.git"
    echo ""
    read -p "Confirma essa URL? (s/n): " CONFIRMA
    if [ "$CONFIRMA" != "s" ]; then
        exit 1
    fi
fi

# Configurar remote
if git remote get-url origin 2>/dev/null; then
    git remote set-url origin "$REPO_URL"
    echo "✅ Remote atualizado"
else
    git remote add origin "$REPO_URL"
    echo "✅ Remote adicionado"
fi

# Verificar branch
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo "📌 Renomeando branch para 'main'..."
    git branch -M main
fi

# Push
echo ""
echo "🚀 Fazendo push para GitHub..."
echo ""
echo "⚠️  Se pedir autenticação:"
echo "   Username: seu-usuario-github"
echo "   Password: Personal Access Token (não é sua senha!)"
echo ""
echo "   Criar token em: https://github.com/settings/tokens"
echo "   Permissões necessárias: repo (full control)"
echo ""

git push -u origin main

if [ $? -eq 0 ]; then
    echo ""
    echo "✅✅✅ CÓDIGO ENVIADO COM SUCESSO! ✅✅✅"
    echo ""
    echo "🌐 Repositório: $REPO_URL"
    echo ""
    echo "📋 PRÓXIMOS PASSOS:"
    echo ""
    echo "1. Acessar EasyPanel"
    echo "2. Serviço 'api' → Aba 'Fonte'"
    echo "3. Selecionar 'Git'"
    echo "4. Repository URL: $REPO_URL"
    echo "5. Branch: main"
    echo "6. Dockerfile Path: Dockerfile"
    echo "7. Adicionar variáveis de ambiente"
    echo "8. Clicar em 'Deploy'"
    echo "9. Aguardar build (2-5 min)"
    echo "10. Testar: https://api.papalino.com.br/api/health"
    echo ""
else
    echo ""
    echo "❌ Erro ao fazer push"
    echo ""
    echo "Possíveis soluções:"
    echo "1. Verificar se o repositório existe no GitHub"
    echo "2. Usar Personal Access Token ao invés de senha"
    echo "3. Verificar permissões do token (precisa 'repo')"
    echo ""
fi

