FROM php:8.3-apache

COPY . /var/www/html/

CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_*; a2enmod mpm_prefork; sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf; sed -i \"s/:80/:${PORT:-80}/\" /etc/apache2/sites-available/000-default.conf; apache2-foreground"]