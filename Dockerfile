FROM php:8.3-cli

WORKDIR /app
ENV APP_DATA_DIR=/data

RUN mkdir -p /data
COPY index.php /app/index.php
COPY railway-start.sh /usr/local/bin/railway-start
RUN chmod 755 /usr/local/bin/railway-start

EXPOSE 8080
CMD ["/usr/local/bin/railway-start"]
