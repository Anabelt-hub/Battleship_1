FROM php:8.2-apache

# 1. Install PostgreSQL client libraries and PHP drivers
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# 2. Enable Apache rewrite for your API routing
RUN a2enmod rewrite

# 3. Copy your project files into the container
COPY . /var/www/html/

# 4. Configure Apache and set permissions (Removed JSON file logic)
RUN printf '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/override.conf \
    && a2enconf override \
    && chown -R www-data:www-data /var/www/html

# 5. Open port 80 for web traffic
EXPOSE 80
