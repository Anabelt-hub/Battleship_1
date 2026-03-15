FROM php:8.2-apache

RUN a2enmod rewrite

COPY . /var/www/html/

RUN printf '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/override.conf \
    && a2enconf override

EXPOSE 80
