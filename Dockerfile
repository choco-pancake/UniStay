# UniStay — PHP 8.2 + Apache + MySQL client + Tailwind build
FROM php:8.2-apache
ARG CACHEBUST=1

# System deps + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    unzip git curl nodejs npm \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql mbstring zip fileinfo \
  && a2enmod rewrite \
  && rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
  && rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
  && a2enmod mpm_prefork \
  && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache doc root = project root (index.php lives here)
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

# Build Tailwind CSS
RUN npm run build:css

# Writable dirs
RUN mkdir -p uploads storage/mail config \
  && chown -R www-data:www-data uploads storage config \
  && chmod -R 775 uploads storage config

# Apache listens on Railway's PORT
ENV PORT=8080
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

EXPOSE 8080

CMD ["apache2-foreground"]