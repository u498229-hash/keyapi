#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
  *[!0-9]*|'') echo "PORT must be numeric" >&2; exit 1 ;;
esac

mkdir -p /data
exec php -S "0.0.0.0:${port}" -t /app /app/index.php
