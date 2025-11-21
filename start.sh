#!/bin/bash
set -e

echo "🚀 Iniciando Laravel API..."

# Otimizar
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Iniciar servidor
php artisan serve --host=0.0.0.0 --port=8000
