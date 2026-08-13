<?php

declare(strict_types=1);

/**
 * De app draait op vendor/ (dat staat in de repo, want niet elke hosting
 * heeft Composer). Het testgereedschap staat in vendor-dev/, zodat het niet
 * mee de server op gaat. De tests hebben allebei nodig.
 */

require __DIR__ . '/../vendor/autoload.php';

$development = __DIR__ . '/../vendor-dev/autoload.php';
if (!is_file($development)) {
    fwrite(STDERR, "Het testgereedschap ontbreekt. Installeer het met:\n\n"
        . "    COMPOSER=composer-dev.json composer install\n\n");
    exit(1);
}
require $development;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PrintScript\\Tests\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('PrintScript\\Tests\\')) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
