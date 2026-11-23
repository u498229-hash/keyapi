FROM php:8.3-apache

RUN a2enmod rewrite \
    && printf '%s\n' '<Directory /var/www/html>' '    Options FollowSymLinks' '    AllowOverride All' '    Require all granted' '</Directory>' > /etc/apache2/conf-available/app-rewrites.conf \
    && a2enconf app-rewrites \
    && mkdir -p /data

ENV APP_DATA_DIR=/data
WORKDIR /var/www/html

COPY index.php /var/www/html/index.php
COPY .htaccess /var/www/html/.htaccess
COPY railway-start.sh /usr/local/bin/railway-start
RUN chmod 755 /usr/local/bin/railway-start

EXPOSE 8080
CMD ["/usr/local/bin/railway-start"]
