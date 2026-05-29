FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libpng-dev libfreetype6-dev libjpeg62-turbo-dev \
    libzip-dev zip unzip git curl default-mysql-client \
    libgd-dev ghostscript \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli zip gd bcmath \
    && rm -rf /var/lib/apt/lists/* \
    && echo "upload_max_filesize = 50M\npost_max_size = 50M" > $PHP_INI_DIR/conf.d/uploads.ini

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --prefer-dist

COPY . .

RUN mkdir -p runtime/cache runtime/log runtime/temp runtime/session runtime/route \
    public/uploads/violations \
    && chmod -R 777 runtime public/uploads

COPY entrypoint.sh /app/
RUN chmod +x /app/entrypoint.sh

ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["php-fpm"]
