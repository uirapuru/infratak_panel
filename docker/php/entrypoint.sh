#!/bin/sh
set -eu

mkdir -p /app/var/cache /app/var/log /var/log/infratak

# In local dev these paths are bind-mounted from the host. PHP-FPM runs as
# www-data, so keep them world-writable to avoid repeated permission issues.
chmod -R 0777 /app/var /var/log/infratak

exec docker-php-entrypoint "$@"