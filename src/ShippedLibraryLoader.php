<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut;

use Composer\Autoload\ClassLoader;

/**
 * Macht die mitgelieferte QR-Bibliothek ladbar, wenn der Shop sie nicht selbst hat.
 *
 * Shopware rechnet ein mitgeliefertes `vendor` beim Einbau als erfüllte Anforderung an, lädt es
 * zur Laufzeit aber nicht. Registriert werden nur die Namensräume der Bibliothek, nicht das ganze
 * `vendor/autoload.php`: So kann keine Klasse des Kerns aus einer zweiten Fassung kommen, egal was
 * im Ordner liegt.
 */
final class ShippedLibraryLoader
{
    /**
     * `endroid/qr-code` und alles, was es laut Lock mitbringt. Der Test vergleicht die Liste mit dem
     * Lock, damit eine neue Abhängigkeit nach einem Update nicht erst im Shop auffällt.
     *
     * @var array<string, string> Namensraum => Pfad unter `vendor/`
     */
    public const NAMESPACES = [
        'Endroid\\QrCode\\' => 'endroid/qr-code/src/',
        'BaconQrCode\\' => 'bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\' => 'dasprid/enum/src/',
    ];

    // Die erste Klasse, die QrCodeService braucht. Findet sie sich, ist die Bibliothek da.
    private const PROBE_CLASS = 'Endroid\\QrCode\\Builder\\Builder';

    public static function register(string $vendorDir): void
    {
        // Findet sich die Klasse schon, bringt der Shop die Bibliothek selbst mit, oder ein vorheriger
        // Start im selben Prozess hat sie registriert. In beiden Fällen stünde ein weiterer Lader nur in der Kette.
        if (!is_dir($vendorDir . '/endroid') || class_exists(self::PROBE_CLASS)) {
            return;
        }

        $loader = new ClassLoader();
        foreach (self::NAMESPACES as $prefix => $path) {
            $loader->addPsr4($prefix, $vendorDir . '/' . $path);
        }

        // Hinten angehängt, damit der Lader des Shops immer zuerst gefragt wird.
        $loader->register(false);
    }
}
