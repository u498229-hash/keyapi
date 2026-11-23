FROM php:8.3-apache

RUN for mpm in mpm_event mpm_worker mpm_prefork; do a2dismod "$mpm" >/dev/null 2>&1 || true; done \
    && a2enmod mpm_prefork rewrite \
    && printf '%s\n' '<Directory /var/www/html>' '    Options FollowSymLinks' '    AllowOverride None' '    Require all granted' '    FallbackResource /index.php' '</Directory>' > /etc/apache2/conf-available/app-rewrites.conf \
    && a2enconf app-rewrites \
    && mkdir -p /data

ENV APP_DATA_DIR=/data
WORKDIR /var/www/html

COPY index.php /var/www/html/index.php
COPY railway-start.sh /usr/local/bin/railway-start
RUN chmod 755 /usr/local/bin/railway-start

EXPOSE 8080
CMD ["/usr/local/bin/railway-start"]
