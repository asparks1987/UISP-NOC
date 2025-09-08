# Use the official PHP-Apache image
FROM php:8.2-apache

# Install required packages and extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    iputils-ping \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY index.php /var/www/html/
COPY assets/ /var/www/html/assets/
COPY buz.mp3 /var/www/html/

# Ensure permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
