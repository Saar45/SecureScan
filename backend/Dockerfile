FROM php:8.4-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libonig-dev \
    python3 \
    python3-pip \
    python3-venv \
    pipx \
    npm \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    gd \
    mbstring \
    xml \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 1. Installation de semgrep (il se mettra tout seul dans /usr/local/bin)
RUN pip3 install semgrep --break-system-packages

# 2. On s'assure simplement que le fichier est exécutable par tous
RUN chmod +x /usr/local/bin/semgrep

# 3. Installation de TruffleHog au même endroit
RUN curl -sSfL https://raw.githubusercontent.com/trufflesecurity/trufflehog/main/scripts/install.sh | sh -s -- -b /usr/local/bin

# Enable Apache modules
RUN a2enmod rewrite headers

# Copy Apache vhost config
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Copy PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy entrypoint script (auto composer install on boot)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Set proper permissions and create runtime directories
# Give www-data full ownership of its home dir so tools (semgrep, npm, composer, trufflehog) can write configs/caches
RUN chown -R www-data:www-data /var/www && \
    mkdir -p /tmp/scans && chmod 777 /tmp/scans && \
    mkdir -p /tmp/reports && chmod 777 /tmp/reports

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
