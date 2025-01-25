# Use PHP 8.2 with Apache
FROM php:8.2-apache

# Install SQLite3 and other useful packages
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite

# Enable Apache modules
RUN a2enmod rewrite

# Set PHP configuration
RUN echo "error_reporting = E_ALL" > /usr/local/etc/php/conf.d/error-reporting.ini
RUN echo "\
session.cookie_lifetime = 2592000\n\
session.gc_maxlifetime = 2592000\n\
session.cookie_httponly = On\n\
session.cookie_samesite = Lax\n\
session.use_strict_mode = On\n\
session.use_cookies = On\n\
session.use_only_cookies = On\n\
" > /usr/local/etc/php/conf.d/session.ini

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Set working directory
WORKDIR /var/www/html/

# Expose port 80
EXPOSE 80
