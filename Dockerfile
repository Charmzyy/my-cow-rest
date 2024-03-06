FROM php:8.2.12-cli

RUN apt-get update -y && apt-get install -y libmcrypt-dev libonig-dev unzip zlib1g-dev libzip-dev


RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN docker-php-ext-install mysqli pdo pdo_mysql mbstring zip


RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && sed -i -e "s/;extension=pdo_mysql/extension=pdo_mysql/" /usr/local/etc/php/php.ini

ENV COMPOSER_ALLOW_SUPERUSER 1

WORKDIR /app
COPY . /app

RUN composer install
RUN php artisan storage:link
EXPOSE 8001


CMD php artisan serve --host=0.0.0.0 --port=8001
