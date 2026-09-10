#!/usr/bin/env php
<?php
/**
 * softpack - build Softaculous/cPanel WordPress backup pairs from a local install.
 *
 * Entry point. Run `softpack help` for usage.
 */

declare(strict_types=1);

define('SOFTPACK_VERSION', '1.0.2');
define('SOFTPACK_ROOT', str_replace('\\', '/', __DIR__));

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "softpack is a command line tool.\n");
    exit(1);
}

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, "softpack needs PHP 7.4 or newer; this is " . PHP_VERSION . ".\n");
    exit(1);
}

foreach (['mysqli', 'zlib', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "softpack needs the '{$ext}' PHP extension, which is not loaded.\n");
        exit(1);
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Softpack\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = SOFTPACK_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

exit((new Softpack\Application($argv))->run());
