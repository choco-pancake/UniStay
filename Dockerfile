FROM php:8.3-apache

# Fix "More than one MPM loaded": disable all, enable only prefork
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork

# Make Apache listen on Railway's PORT instead of 80
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:${PORT}/' /etc/apache2/sites-available/000-default.conf

# Copy your app into Apache's web root
COPY . /var/www/html/