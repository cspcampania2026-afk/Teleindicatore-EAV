FROM php:8.3-apache-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends supervisor libsqlite3-dev libpq-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
 && for ext in pdo_sqlite pdo_pgsql mbstring dom curl; do \
      if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext"; then docker-php-ext-install -j2 "$ext"; fi; \
    done \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers setenvif

COPY . /var/www/html/
COPY deploy/supervisord.conf /etc/csp-supervisord.conf
COPY deploy/apache-csp.conf /etc/apache2/conf-available/csp.conf
COPY deploy/entrypoint.sh /usr/local/bin/csp-entrypoint

RUN chmod 755 /usr/local/bin/csp-entrypoint \
 && a2enconf csp \
 && mkdir -p /var/lib/csp-data \
 && chown www-data:www-data /var/lib/csp-data \
 && find /var/www/html -type f -name '*.php' -exec php -l {} \; \
 && php /var/www/html/tests/regression.php \
 && php /var/www/html/tests/parser.php \
 && php /var/www/html/tests/history.php \
 && php /var/www/html/tests/history_pairing.php \
 && php /var/www/html/tests/effective_reports.php \
 && php /var/www/html/tests/telegram.php \
 && php /var/www/html/tests/monitor.php \
 && php /var/www/html/tests/terminal_delays.php \
 && php /var/www/html/tests/line_catalog.php \
 && php /var/www/html/tests/accounts.php \
 && php /var/www/html/tests/database.php \
 && php /var/www/html/tests/reports.php \
 && php /var/www/html/tests/delay_statistics.php \
 && php /var/www/html/tests/line_analysis.php

ENV CSP_DATA_DIR=/var/lib/csp-data \
    CSP_ENABLE_MONITOR=0
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/csp-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/csp-supervisord.conf"]
