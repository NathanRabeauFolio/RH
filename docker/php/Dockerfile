FROM php:8.3-apache

RUN a2enmod rewrite headers expires     && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf     && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN {       echo 'upload_max_filesize=12M';       echo 'post_max_size=16M';       echo 'max_file_uploads=20';       echo 'memory_limit=256M';     } > /usr/local/etc/php/conf.d/uploads.ini
