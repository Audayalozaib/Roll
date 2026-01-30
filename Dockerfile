FROM php:8.1-apache

# تثبيت الإضافات المطلوبة
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_sqlite sqlite3 \
    && a2enmod rewrite

# تثبيت امتداد zip بشكل منفصل
RUN docker-php-ext-install zip

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# إعدادات Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# نسخ ملفات المشروع
COPY . /var/www/html/

# تثبيت الاعتماديات
WORKDIR /var/www/html/
RUN composer install --no-dev --optimize-autoloader

# إنشاء مجلد قاعدة البيانات والسجلات
RUN mkdir -p data logs && chmod -R 777 data logs

# إعدادات الأذونات
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 755 /var/www/html/
RUN chmod -R 777 /var/www/html/data/ /var/www/html/logs/

# كشف المنفذ
EXPOSE 8080

# أمر التشغيل
CMD ["apache2-foreground"]
