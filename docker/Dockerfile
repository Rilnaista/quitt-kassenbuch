# Quitt - Docker-Image (REQ-063). Layout: .claude/docs/release-layout.md
# Build-Kontext = Repo-Root (docker/Dockerfile.dockerignore laesst nur dist-release/ + entrypoint durch):
#   scripts/build-release.sh 0.1.0
#   docker build -f docker/Dockerfile --build-arg VERSION=0.1.0 -t quitt .
FROM php:8.3-apache

ARG VERSION
LABEL org.opencontainers.image.title="Quitt Kassenbuch" \
      org.opencontainers.image.source="https://github.com/Rilnaista/quitt-kassenbuch" \
      org.opencontainers.image.licenses="AGPL-3.0" \
      org.opencontainers.image.version="${VERSION}"

# zip (Sicherung/Update/Setup); pdo_sqlite, mbstring, curl, json sind im Basisimage enthalten
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev; \
    docker-php-ext-install -j"$(nproc)" zip; \
    apt-get purge -y --auto-remove libzip-dev; \
    apt-get install -y --no-install-recommends libzip4 || apt-get install -y --no-install-recommends libzip5 || true; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers; \
    sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf; \
    printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/quitt.conf; \
    a2enconf quitt

# Release-Paket entpacken (kein unzip noetig: PHP-ZipArchive) + setup.php (Lock-Modus im Docker)
COPY dist-release/quitt-${VERSION}.zip /tmp/quitt.zip
COPY dist-release/setup.php /var/www/html/setup.php
RUN set -eux; \
    php -r '$z=new ZipArchive; if($z->open("/tmp/quitt.zip")!==true||!$z->extractTo("/var/www/html")){fwrite(STDERR,"Paket defekt\n");exit(1);}'; \
    rm /tmp/quitt.zip; \
    test -f /var/www/html/app/vendor/autoload.php; \
    chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/quitt-entrypoint
RUN chmod +x /usr/local/bin/quitt-entrypoint

ENV QUITT_DATA_DIR=/data \
    QUITT_DOCKER=1 \
    QUITT_PORT=8080
VOLUME /data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${QUITT_PORT:-8080}/api/v1/health" >/dev/null || exit 1

ENTRYPOINT ["quitt-entrypoint"]
CMD ["apache2-foreground"]
