FROM php:8.2-apache

# Install required extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && rm -rf /var/lib/apt/lists/*

# Copy the application files into Apache's web root
COPY boarding_hauz/ /var/www/html/boarding_hauz/

# Point Apache's DocumentRoot to the boarding_hauz folder and grant permissions
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/boarding_hauz|g' /etc/apache2/sites-available/000-default.conf \
    && echo '<Directory /var/www/html/boarding_hauz/>' >> /etc/apache2/apache2.conf \
    && echo '    Options Indexes FollowSymLinks' >> /etc/apache2/apache2.conf \
    && echo '    AllowOverride All' >> /etc/apache2/apache2.conf \
    && echo '    Require all granted' >> /etc/apache2/apache2.conf \
    && echo '</Directory>' >> /etc/apache2/apache2.conf

EXPOSE 80