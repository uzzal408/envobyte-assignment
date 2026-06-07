<?php

require __DIR__.'/../vendor/autoload.php';

/*
 | Make `php artisan test` / `vendor/bin/phpunit` use the testing config without
 | needing extra `-e` flags inside the dev container.
 |
 | The container injects `.env` as real OS environment variables (docker-compose
 | `env_file: .env`), which land in $_SERVER. Laravel's env() reads $_SERVER
 | BEFORE $_ENV, and PHPUnit's `<env force="true">` only writes $_ENV/putenv() —
 | not $_SERVER — so the dev values (QUEUE_CONNECTION=redis, CACHE_DRIVER=redis,
 | …) would otherwise shadow the test values and the queued batch would never run
 | inline. Re-asserting them into $_SERVER here, before the app boots, fixes that.
 |
 | Only behavioural switches are pinned. DB host/name/credentials are intentionally
 | left overridable (phpunit.xml provides defaults; CI can still point at its own
 | database), matching what scripts/../run-tests.sh exports.
 */
$testingEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'testing',
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_DRIVER' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'FILESYSTEM_DISK' => 'public',
];

foreach ($testingEnv as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}
