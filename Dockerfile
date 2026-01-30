FROM php:8.1-apache

# تثبيت المتطلبات
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_sqlite sqlite3 zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache config (Railway / Render / Fly)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# نسخ المشروع
WORKDIR /var/www/html
COPY . .

# تثبيت الاعتماديات (لو composer.json موجود)
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader; fi

# مجلدات SQLite / Logs
RUN mkdir -p data logs \
 && chown -R www-data:www-data data logs \
 && chmod -R 775 data logs

# Apache يعمل على PORT من Railway
ENV PORT=80
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/ports.conf \
 && sed -i "s/:80>/:\\\${PORT}>/g" /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]
