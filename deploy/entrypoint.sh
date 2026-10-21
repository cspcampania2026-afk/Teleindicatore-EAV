#!/bin/sh
set -eu
umask 0077
csp_port="${PORT:-10000}"
case "$csp_port" in ''|*[!0-9]*) echo 'PORT deve essere un numero.' >&2; exit 1;; esac
if [ "$csp_port" -lt 1 ] || [ "$csp_port" -gt 65535 ]; then echo 'PORT non valida.' >&2; exit 1; fi
case "${CSP_DATA_DIR:-}" in /*) ;; *) echo 'CSP_DATA_DIR deve essere un percorso assoluto.' >&2; exit 1;; esac
case "$CSP_DATA_DIR" in /var/www|/var/www/*|/) echo 'Cartella dati non valida: deve essere esterna alla cartella web.' >&2; exit 1;; esac
mkdir -p "$CSP_DATA_DIR"
chown www-data:www-data "$CSP_DATA_DIR"
chmod 700 "$CSP_DATA_DIR"
sed -i "s/^Listen 80$/Listen $csp_port/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$csp_port>/" /etc/apache2/sites-available/000-default.conf
cp /etc/csp-supervisord.conf /tmp/csp-supervisord.conf
if [ "${CSP_ENABLE_MONITOR:-0}" = 1 ]; then
    cat >> /tmp/csp-supervisord.conf <<'MONITOR'

[program:monitor]
command=/usr/local/bin/php /var/www/html/monitor.php
user=www-data
autostart=true
autorestart=true
startsecs=5
startretries=20
stopasgroup=true
killasgroup=true
stopsignal=TERM
stopwaitsecs=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
MONITOR
fi
apache2ctl configtest
# Use the generated configuration that includes the optional monitor.
if [ "${1:-}" = /usr/bin/supervisord ]; then
    exec /usr/bin/supervisord -n -c /tmp/csp-supervisord.conf
fi
exec "$@"
