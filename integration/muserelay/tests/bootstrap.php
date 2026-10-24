<?php
declare(strict_types=1);

// Deliberately DO NOT load bootstrap/app.php, artisan, Dotenv or the application's PHPUnit config.
if (getenv('APP_ENV') !== 'testing') throw new RuntimeException('APP_ENV=testing is required');
$vendor = getenv('MUSERELAY_TEST_VENDOR');
$core = realpath((string) getenv('MUSERELAY_CORE_TEST_SOURCE'));
if (!$vendor || !is_file($vendor) || !$core || is_file($core.'/.env')) throw new RuntimeException('Use an explicit dependency autoloader and a Core source worktree WITHOUT .env');
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
require $vendor;
require dirname(__DIR__).'/BridgeSignature.php';
define('BRIDGE_CORE_TEST_SOURCE', $core);
spl_autoload_register(function (string $class) use ($core): void {
    $prefix = 'MuseRelay\\AwsEngagementBridge\\Core\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__).'/src/Core/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    } elseif (str_starts_with($class, 'App\\')) {
        $file = $core.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    } else return;
    if (is_file($file)) require $file;
}, true, true);
