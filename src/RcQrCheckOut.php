<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut;

use Shopware\Core\Framework\Plugin;

/**
 * Einstieg der Erweiterung, der beim Start die mitgelieferte QR-Bibliothek anmeldet.
 *
 * Die Anmeldung steht in boot() und nicht in einem Dienst, weil boot() bei jedem Start des Kernels
 * läuft, bevor irgendein Dienst die Bibliothek anfasst.
 */
class RcQrCheckOut extends Plugin
{
    public function boot(): void
    {
        parent::boot();

        ShippedLibraryLoader::register(\dirname(__DIR__) . '/vendor');
    }
}
