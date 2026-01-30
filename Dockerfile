FROM php:8.1-apache

# تثبيت الإضافات المطلوبة
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_sqlite sqlite3 \
    && a2enmod rewrite

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# إعدادات Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# نسخ ملفات المشروع
COPY . /var/www/html/

# تثبيت الاعتماديات
WORKDIR /var/www/html/
RUN composer install --no-dev --optimize-autoloader

# إنشاء مجلد قاعدة البيانات
RUN mkdir -p data && chmod 777 data

# إعدادات الأذونات
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 755 /var/www/html/
RUN chmod -R 777 /var/www/html/data/

# كشف المنفذ
EXPOSE 8080

# أمر التشغيل
CMD ["apache2-foreground"]
