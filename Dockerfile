FROM php:8.3-apache

# Remove every enabled MPM, then enable only prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork

# Listen on Railway's PORT
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:${PORT}/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/