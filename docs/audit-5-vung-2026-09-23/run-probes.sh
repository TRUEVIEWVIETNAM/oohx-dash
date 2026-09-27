#!/bin/sh
set -eu
mkdir -p /tmp/oohx-audit
cd /tmp/oohx-audit
cp -R /source/app /source/bootstrap /source/config /source/routes .
cp /source/composer.json .
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
ln -s /source/vendor vendor
export APP_BASE_PATH=/tmp/oohx-audit APP_ENV=testing APP_DEBUG=false
export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export APP_URL=http://localhost DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=stderr
php /source/docs/audit-5-vung-2026-09-23/probe-services.php > /results/service-probes.json
cat /results/service-probes.json
