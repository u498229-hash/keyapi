#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
  *[!0-9]*|'') echo "PORT must be numeric" >&2; exit 1 ;;
esac

sed -ri "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \\*:80>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

mkdir -p /data
chown -R www-data:www-data /data
exec apache2-foreground
