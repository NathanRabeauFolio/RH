FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    poppler-utils \
    ghostscript \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers expires \
 && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
 && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
