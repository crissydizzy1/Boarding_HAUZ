FROM php:8.2-apache

# Extensions used by the application (MySQLi database access and multibyte text).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && rm -rf /var/lib/apt/lists/*

# Keep the /boarding_hauz URL base used by the application's links.
COPY boarding_hauz/ /var/www/html/boarding_hauz/

EXPOSE 80
