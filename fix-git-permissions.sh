#!/bin/bash
set -e

echo "🔧 Corrigindo permissões Git..."

# 1. Adicionar como diretório seguro
git config --global --add safe.directory /var/www/clinica-backend

# 2. Ajustar propriedade do diretório (se necessário)
# Verificar dono atual
CURRENT_OWNER=$(stat -c '%U' /var/www/clinica-backend)
echo "📋 Dono atual: $CURRENT_OWNER"

# Se não for root, ajustar
if [ "$CURRENT_OWNER" != "root" ]; then
    echo "⚠️  Ajustando propriedade para root..."
    chown -R root:root /var/www/clinica-backend
fi

# 3. Verificar se Git está funcionando
echo ""
echo "✅ Testando Git..."
if git status >/dev/null 2>&1; then
    echo "✅ Git funcionando corretamente!"
else
    echo "❌ Ainda há problemas. Tentando outra solução..."
    
    # Solução alternativa: marcar como seguro para qualquer usuário
    git config --global --add safe.directory '*'
fi

# 4. Configurar Git global se ainda não configurado
if ! git config --global user.name >/dev/null 2>&1; then
    echo ""
    echo "⚙️  Configurando Git..."
    read -p "Seu nome: " GIT_NAME
    read -p "Seu email: " GIT_EMAIL
    
    git config --global user.name "$GIT_NAME"
    git config --global user.email "$GIT_EMAIL"
fi

echo ""
echo "✅ Configuração Git completa!"
echo ""
echo "📋 Verificação final:"
git config --list | grep safe.directory
git config --list | grep user.name
git config --list | grep user.email

