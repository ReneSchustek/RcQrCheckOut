<?php

declare(strict_types=1);

/*
 * Bootstrap der Messungen. Lädt den Autoloader der Shopware-Installation, in der die Erweiterung
 * liegt, dazu den eigenen der Erweiterung, wenn sie ihre QR-Bibliothek mitbringt, und meldet die
 * Namensräume an. Einen Kern braucht die Messung nicht.
 */

$projectRoot = getenv('PROJECT_ROOT');
if (!\is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 4);
}
if (!is_file($projectRoot . '/vendor/autoload.php')) {
    throw new RuntimeException("Keine Shopware-Installation unter {$projectRoot}; PROJECT_ROOT setzen.");
}

$classLoader = require $projectRoot . '/vendor/autoload.php';
$classLoader->addPsr4('Ruhrcoder\\RcQrCheckOut\\Benchmarks\\', __DIR__);
$classLoader->addPsr4('Ruhrcoder\\RcQrCheckOut\\', dirname(__DIR__) . '/src');

// Auf Staging und Live liegt endroid/qr-code im vendor/ der Erweiterung, nicht in dem des Shops.
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
