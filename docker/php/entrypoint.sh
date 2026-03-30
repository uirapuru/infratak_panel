#!/bin/sh
set -eu

mkdir -p /var/log/infratak

# In local dev these paths are bind-mounted from the host. PHP-FPM runs as
# www-data, so keep them world-writable to avoid repeated permission issues.
if [ -d /app/var ] && [ -w /app/var ]; then
	chmod -R 0777 /app/var
fi
# In prod compose, /app can be read-only while /app/var/cache is a writable volume.
# Ensure cache directory is writable even when recursive chmod on /app/var is skipped.
if [ -d /app/var/cache ]; then
	chmod -R 0777 /app/var/cache || true
fi
chmod -R 0777 /var/log/infratak

exec docker-php-entrypoint "$@"