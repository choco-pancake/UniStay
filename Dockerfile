# UniStay — PHP 8.2 + Apache
FROM php:8.2-apache

# System deps + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    unzip git curl nodejs npm \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql mbstring zip fileinfo \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache doc root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Workdir
WORKDIR /var/www/html

# Copy dependency manifests first (layer cache)
COPY composer.json composer.lock* package.json package-lock.json* ./

# Install PHP deps
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install node deps
RUN npm install

# Copy the rest of the app
COPY . .

# Fix Apache MPM: remove conflicting modules
# Disable conflicting MPMs at build time
RUN a2dismod mpm_event 2>/dev/null || true; a2dismod mpm_worker 2>/dev/null || true; a2enmod mpm_prefork

# Build Tailwind CSS
RUN npm run build:css

# Writable dirs
RUN mkdir -p uploads storage/mail config \
  && chown -R www-data:www-data uploads storage config \
  && chmod -R 775 uploads storage config

# Startup script: configure port at runtime, then start Apache
RUN printf '#!/bin/bash\n\
# Forcefully remove conflicting MPM symlinks\n\
rm -f /etc/apache2/mods-enabled/mpm_event.load\n\
rm -f /etc/apache2/mods-enabled/mpm_event.conf\n\
rm -f /etc/apache2/mods-enabled/mpm_worker.load\n\
rm -f /etc/apache2/mods-enabled/mpm_worker.conf\n\
# Ensure prefork is enabled\n\
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load\n\
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf\n\
PORT=${PORT:-8080}\n\
sed -i "s/Listen .*/Listen $PORT/" /etc/apache2/ports.conf\n\
sed -i "s/<VirtualHost \\*:.*>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/*.conf\n\
exec apache2-foreground\n' > /usr/local/bin/start.sh \
  && chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]