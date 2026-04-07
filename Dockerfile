FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip

RUN a2enmod rewrite headers

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/html/

# Coolify proxy expects port 3000 — override Apache default port
RUN sed -i 's/Listen 80/Listen 3000/' /etc/apache2/ports.conf

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 3000
