FROM php:8.2.12-cli

RUN apt-get update -y && apt-get install -y libmcrypt-dev && apt-get install -y libonig-dev


RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN docker-php-ext-install pdo mbstring

ENV COMPOSER_ALLOW_SUPERUSER 1

WORKDIR /app
COPY . /app

RUN composer install

EXPOSE 8001
CMD php artisan serve --host=0.0.0.0 --port=8001