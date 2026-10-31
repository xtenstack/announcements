<?php
declare(strict_types=1);

/**
 * Bootstrap for the pure-PHP suite (phpunit.unit.xml, tests/Unit/): no
 * Docker stack, no Phalcon, no app_skeleton checkout — only the
 * framework-free classes under src/Feeds/ and src/Api/, autoloaded here
 * with the same psr-4 mapping this package's composer.json declares.
 * When the full suite runs inside the stack (phpunit.xml,
 * tests/bootstrap.php) composer's own autoloader serves the same classes
 * and this file is not involved.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Announcements\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

define('ANNOUNCEMENTS_FIXTURES', __DIR__ . '/fixtures/statuspage');
