#!/usr/bin/env bash
#
# Single-command test runner for the dockerised dev environment.
#
# The app container injects .env as real OS environment variables (env_file:.env),
# which Laravel prioritises over phpunit.xml. So we pass the testing values as
# process env here, ensure the dedicated test database exists + is migrated, then
# run PHPUnit. Pass any phpunit args through, e.g.:
#
#   ./run-tests.sh                       # full suite
#   ./run-tests.sh --filter Import       # just the import tests
#
set -euo pipefail

COMPOSE="docker compose"
TEST_ENV=(
  -e APP_ENV=testing
  -e DB_CONNECTION=testing
  -e DB_TEST_DRIVER=mysql
  -e DB_TEST_HOST=mysql
  -e DB_TEST_PORT=3306
  -e DB_TEST_DATABASE=monica_test
  -e DB_TEST_USERNAME=homestead
  -e DB_TEST_PASSWORD=secret
  -e QUEUE_CONNECTION=sync
  -e CACHE_DRIVER=array
  -e SESSION_DRIVER=array
  -e MAIL_MAILER=array
)

echo "==> Ensuring monica_test database exists"
$COMPOSE exec -T mysql sh -c \
  'mysql -uroot -psekret_root_password -e "CREATE DATABASE IF NOT EXISTS monica_test; GRANT ALL PRIVILEGES ON monica_test.* TO \"homestead\"@\"%\"; FLUSH PRIVILEGES;"' \
  >/dev/null 2>&1

echo "==> Migrating the test database (fresh, so the schema always matches migrations)"
$COMPOSE exec -T "${TEST_ENV[@]}" app php artisan migrate:fresh --database=testing --force >/dev/null

echo "==> Running PHPUnit"
$COMPOSE exec -T "${TEST_ENV[@]}" app vendor/bin/phpunit "$@"
