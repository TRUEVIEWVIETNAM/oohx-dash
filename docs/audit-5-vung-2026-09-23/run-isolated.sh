#!/bin/sh
# Audit harness only. Run against a read-only /source mount and an empty /results directory.
set -eu
mkdir -p /tmp/oohx-audit
cd /tmp/oohx-audit
cp -R /source/app /source/bootstrap /source/config /source/database /source/routes /source/resources /source/tests .
cp /source/artisan /source/composer.json /source/composer.lock /source/phpunit.xml .
mkdir -p public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
if [ -d /source/public/build ]; then cp -R /source/public/build public/; fi
ln -s /source/vendor vendor
export APP_BASE_PATH=/tmp/oohx-audit
export APP_ENV=testing APP_DEBUG=false APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export APP_URL=http://localhost DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=stderr
php --version > /results/php-version.txt
php artisan route:list --json > /results/routes.json
set +e
php vendor/bin/phpunit --do-not-cache-result --stop-on-error --log-junit /results/phpunit.xml > /results/phpunit.txt 2>&1
result=$?
printf '%s\n' "$result" > /results/phpunit-exit-code.txt
cat /results/phpunit.txt
exit "$result"
