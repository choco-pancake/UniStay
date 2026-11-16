#!/bin/bash
set -e

PORT="${PORT:-8080}"
echo "[UniStay] Starting container on port $PORT..."

# Generate Nginx configuration dynamically
cat << EOF > /etc/nginx/conf.d/default.conf
server {
    listen $PORT;
    server_name _;
    root /var/www/html;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location ~ /\.(ht|git) {
        deny all;
    }
}
EOF

# Ensure sites-enabled is cleared so only default.conf is active
rm -f /etc/nginx/sites-enabled/* 2>/dev/null || true

# Test Nginx configuration before proceeding
echo "[UniStay] Testing Nginx configuration..."
nginx -t

# Start PHP-FPM in background
echo "[UniStay] Starting PHP-FPM..."
php-fpm -D

# Start Nginx in foreground
echo "[UniStay] Starting Nginx on port $PORT..."
exec nginx -g "daemon off;"
