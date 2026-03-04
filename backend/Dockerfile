# 1. Utilisation de l'image Apache avec PHP 8.4 pour la compatibilité Doctrine
FROM php:8.4-apache

# Installation des dépendances système
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
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Installation des extensions PHP indispensables pour Symfony
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    gd \
    mbstring \
    xml \
    opcache

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Installation des outils de sécurité
RUN pip3 install semgrep --break-system-packages
RUN curl -sSfL https://raw.githubusercontent.com/trufflesecurity/trufflehog/main/scripts/install.sh | sh -s -- -b /usr/local/bin

# Configuration d'Apache
RUN a2enmod rewrite headers

# Copie des configurations
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# --- SECTION CRITIQUE : REPARATION DE L'ENTRYPOINT ---
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Cette commande supprime les fins de ligne Windows (\r) pour éviter l'erreur "no such file or directory"
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh

# On rend le script exécutable
RUN chmod +x /usr/local/bin/entrypoint.sh
# -----------------------------------------------------

# Dossier de travail
WORKDIR /var/www/html

# Gestion des permissions pour l'utilisateur Apache (www-data)
RUN chown -R www-data:www-data /var/www && \
    mkdir -p /tmp/scans && chmod 777 /tmp/scans && \
    mkdir -p /tmp/reports && chmod 777 /tmp/reports

EXPOSE 80

# Lancement du script de démarrage
ENTRYPOINT ["entrypoint.sh"]