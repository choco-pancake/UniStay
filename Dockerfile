# UniStay — PHP 8.2 + Nginx + PHP-FPM
FROM php:8.2-fpm

# Install system dependencies + Nginx + required PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    nginx unzip git curl \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql mbstring zip fileinfo \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Production PHP settings
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
  && sed -i 's/upload_max_filesize = .*/upload_max_filesize = 50M/' "$PHP_INI_DIR/php.ini" \
  && sed -i 's/post_max_size = .*/post_max_size = 50M/' "$PHP_INI_DIR/php.ini" \
  && sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI_DIR/php.ini"

# Explicitly ensure PHP-FPM listens on 127.0.0.1:9000
RUN sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-docker.conf 2>/dev/null || true

# Forward Nginx access and error logs to Docker stdout and stderr
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
  && ln -sf /dev/stderr /var/log/nginx/error.log

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer manifests first for Docker caching
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application source code
COPY . .

# Ensure storage, uploads, and config have proper write permissions
RUN mkdir -p uploads storage/mail config \
  && chown -R www-data:www-data uploads storage config \
  && chmod -R 775 uploads storage config

# Clean default Nginx site configurations
RUN rm -f /etc/nginx/sites-enabled/* /etc/nginx/sites-available/* /etc/nginx/conf.d/*

# Startup script
COPY docker-start.sh /usr/local/bin/docker-start.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-start.sh \
  && chmod +x /usr/local/bin/docker-start.sh

EXPOSE 8080

CMD ["/usr/local/bin/docker-start.sh"]
