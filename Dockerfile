# UniStay — PHP 8.2 + Nginx + PHP-FPM (avoids Apache MPM issues)
FROM php:8.2-fpm

# System deps + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    nginx unzip git curl nodejs npm \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql mbstring zip fileinfo \
  && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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

# Build Tailwind CSS
RUN npm run build:css

# Writable dirs
RUN mkdir -p uploads storage/mail config \
  && chown -R www-data:www-data uploads storage config \
  && chmod -R 775 uploads storage config

# Nginx config
COPY docker/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
  && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Startup script
RUN printf '#!/bin/bash\n\
PORT=${PORT:-8080}\n\
sed -i "s/listen [0-9]*;/listen $PORT;/" /etc/nginx/sites-available/default\n\
php-fpm -D\n\
exec nginx -g "daemon off;"\n' > /usr/local/bin/start.sh \
  && chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
