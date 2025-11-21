#!/bin/bash

echo "📦 Criando migrations do sistema..."

# 1. Serviços
php artisan make:migration create_servicos_table

# 2. Faturas
php artisan make:migration create_faturas_table
php artisan make:migration create_fatura_itens_table

# 3. NFSe
php artisan make:migration create_nfse_table
php artisan make:migration create_nfse_lotes_table

# 4. Títulos (Contas a Receber)
php artisan make:migration create_titulos_table
php artisan make:migration create_titulo_baixas_table

# 5. Cobrancas
php artisan make:migration create_cobrancas_table
php artisan make:migration create_remessas_bancarias_table

# 6. Plano de Contas
php artisan make:migration create_plano_contas_table

# 7. Centros de Custo
php artisan make:migration create_centros_custo_table

# 8. Configurações
php artisan make:migration create_configuracoes_table

echo "✅ Migrations criadas!"
ls -la database/migrations/ | tail -20
