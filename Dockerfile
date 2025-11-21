FROM php:8.2-apache

# 1. Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev

# 2. Limpar cache do apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. Configurar extensões
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-configure intl

# 4. Instalar extensões PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# 5. Habilitar mod_rewrite
RUN a2enmod rewrite

# 6. Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 7. Definir diretório
WORKDIR /var/www/html

# 8. Copiar apenas arquivos de dependência
COPY composer.json composer.lock ./

# 9. Instalar dependências
RUN composer install --no-interaction --no-scripts --no-autoloader

# 10. Copiar arquivos do projeto
COPY . .

# --- CORREÇÃO ESSENCIAL AQUI ---
# Remove especificamente os caches que registram o Pail (pacote de dev) e causam erro
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# 11. Autoloader otimizado
# Mantemos o --no-scripts para segurança
RUN composer dump-autoload --optimize --no-dev --no-scripts

# 12. Permissões
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 13. Configurar Apache
ENV APACHE_DOCUMENT_ROOT="/var/www/html/public"
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# 14. Expor porta 80
EXPOSE 80
