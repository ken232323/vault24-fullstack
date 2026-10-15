<?php
declare(strict_types=1);

// Minimal PSR-4 autoloader: Vault24\Foo\Bar -> src/Foo/Bar.php (no Composer needed).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vault24\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
