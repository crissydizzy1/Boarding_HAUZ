FROM php:8.2-apache

# Install required extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && rm -rf /var/lib/apt/lists/*

# Copy your application files into the boarding_hauz subfolder
COPY boarding_hauz/ /var/www/html/boarding_hauz/

# Configure Apache to listen on port 10000 (Required by Render)
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Create a simple redirect in Apache's default root so visiting the base URL goes straight to your app
RUN echo "<?php header('Location: /boarding_hauz/'); exit;" > /var/www/html/index.php

EXPOSE 10000