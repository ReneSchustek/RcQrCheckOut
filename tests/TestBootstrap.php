<?php

declare(strict_types=1);

// Die Unit-Tests laufen ohne Shopware-Kernel. Zuerst kommt der Lader der Erweiterung mit der
// QR-Bibliothek, dann der des Shops vier Ebenen höher (tests, Erweiterung, plugins, custom).
$pluginAutoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($pluginAutoloader)) {
    require_once $pluginAutoloader;
}

$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Eigene PSR-4-Abbildung, damit die Tests auch dann laufen, wenn die Erweiterung in keinem
// Composer-Lader eingetragen ist.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcQrCheckOut\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcQrCheckOut\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }

        $relative = substr($class, $length);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require_once $file;

            return;
        }
    }
});
