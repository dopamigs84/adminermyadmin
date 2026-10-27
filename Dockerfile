FROM php:8.3-apache-bookworm

# Microsoft ODBC 18 + both PHP drivers. Ubuntu can run this Linux container as-is.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg unixodbc-dev libpq-dev libonig-dev \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/microsoft-prod.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql pdo_pgsql mbstring \
    && pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
COPY public/ public/
COPY app/ app/
COPY vendor/ vendor/
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/myadmin.ini

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);'
