<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcQrCheckOut\ShippedLibraryLoader;

/**
 * Die QR-Bibliothek muss sich laden lassen, wenn nur die Erweiterung sie mitbringt.
 *
 * Der normale Testlauf lädt das `vendor` der Erweiterung selbst und kann deshalb nie zeigen, ob
 * der Shop es tut. Die Proben laufen in einem eigenen PHP-Prozess, der nur den Composer-Lader und
 * die Klassen der Erweiterung kennt, so wie ein Shop ohne die Bibliothek im eigenen `vendor`.
 */
final class ShippedLibraryLoaderTest extends TestCase
{
    private const BUILDER = 'Endroid\\QrCode\\Builder\\Builder';

    /**
     * Was: Ohne Registrierung ist die Bibliothek im nackten Prozess nicht da.
     * Warum: Die Gegenprobe zeigt, dass der Prozess wirklich so leer ist wie der Shop. Fände er
     *        die Klasse auch so, bewiese die nächste Probe nichts.
     */
    public function testTheBareProcessDoesNotKnowTheLibrary(): void
    {
        self::assertSame('missing', $this->runIsolated('', 'echo class_exists($builder) ? "found" : "missing";'));
    }

    /**
     * Was: Der Start der Erweiterung macht die Bibliothek ladbar, und der GiroCode wird gezeichnet.
     * Warum: Fehlt die Anmeldung, scheitert jede Vorkasse-Bestellung mit „Class Builder not found".
     */
    public function testBootingThePluginMakesTheGiroCodeRender(): void
    {
        $output = $this->runIsolated(
            '(new Ruhrcoder\\RcQrCheckOut\\RcQrCheckOut(true, $pluginDir))->boot();',
            'echo substr((new Ruhrcoder\\RcQrCheckOut\\Service\\QrCodeService())->generateSvg("BCD"), 0, 200);',
        );

        self::assertStringContainsString('<svg', $output);
    }

    /**
     * Was: Ein zweiter Start registriert nichts doppelt.
     * Warum: Der Kern startet die Erweiterungen bei jedem Neuaufbau des Containers neu. Jeder
     *        weitere Lader stünde sonst in der Kette und würde bei jeder unbekannten Klasse befragt.
     */
    public function testBootingTwiceRegistersOneLoader(): void
    {
        $output = $this->runIsolated(
            '$before = count(spl_autoload_functions());'
            . '(new Ruhrcoder\\RcQrCheckOut\\RcQrCheckOut(true, $pluginDir))->boot();'
            . '(new Ruhrcoder\\RcQrCheckOut\\RcQrCheckOut(true, $pluginDir))->boot();',
            'echo count(spl_autoload_functions()) - $before;',
        );

        self::assertSame('1', $output);
    }

    /**
     * Was: Ohne `vendor` neben der Erweiterung passiert nichts.
     * Warum: So liegt die Erweiterung, wenn der Shop sie über Composer installiert hat. Dann
     *        bringt der Shop die Bibliothek, und ein Lader ins Leere wäre nur Ballast.
     */
    public function testWithoutAVendorDirectoryNothingIsRegistered(): void
    {
        $output = $this->runIsolated(
            '$before = count(spl_autoload_functions());'
            . 'Ruhrcoder\\RcQrCheckOut\\ShippedLibraryLoader::register(sys_get_temp_dir() . "/rc-qr-no-vendor");',
            'echo count(spl_autoload_functions()) - $before;',
        );

        self::assertSame('0', $output);
    }

    /**
     * Was: Die Namensräume decken alles ab, was `endroid/qr-code` laut Lock mitbringt.
     * Warum: Kommt mit einem Update der Bibliothek eine Abhängigkeit dazu, fehlt sie sonst erst
     *        im Shop, und zwar wieder still im Fehlerprotokoll.
     */
    public function testTheNamespacesCoverTheWholeLibrary(): void
    {
        $lockFile = \dirname(__DIR__, 2) . '/composer.lock';
        if (!is_file($lockFile)) {
            self::markTestSkipped('Kein composer.lock, ohne es lässt sich die Bibliothek nicht vermessen.');
        }

        $lock = json_decode((string) file_get_contents($lockFile), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        $packages = [];
        foreach ($lock['packages'] as $package) {
            $packages[$package['name']] = $package;
        }

        $expected = [];
        $pending = ['endroid/qr-code'];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($expected[$name])) {
                continue;
            }

            foreach ($packages[$name]['autoload']['psr-4'] as $prefix => $path) {
                $expected[$name] = [$prefix, $name . '/' . rtrim($path, '/') . '/'];
            }

            foreach (array_keys($packages[$name]['require'] ?? []) as $dependency) {
                $dependency = (string) $dependency;
                if ($dependency !== 'php' && !str_starts_with($dependency, 'ext-')) {
                    $pending[] = $dependency;
                }
            }
        }

        $actual = [];
        foreach (ShippedLibraryLoader::NAMESPACES as $prefix => $path) {
            $actual[] = [$prefix, $path];
        }

        self::assertEqualsCanonicalizing(array_values($expected), $actual);
    }

    /**
     * Startet einen PHP-Prozess mit dem Lader des Testlaufs, aus dem die Bibliothek entfernt ist.
     *
     * Der Testlauf kennt alles, was die Plugin-Klasse aus Shopware und Symfony erbt. Abgemeldet und
     * ohne die Namensräume der Bibliothek neu aufgebaut, entspricht er einem Shop, der die
     * Bibliothek nicht im eigenen `vendor` hat.
     */
    private function runIsolated(string $setup, string $probe): string
    {
        $script = '<?php '
            . '$pluginDir = ' . var_export(\dirname(__DIR__, 2), true) . ';'
            . '$builder = ' . var_export(self::BUILDER, true) . ';'
            . '$library = ' . var_export(array_keys(ShippedLibraryLoader::NAMESPACES), true) . ';'
            . '$full = require $pluginDir . "/vendor/autoload.php";'
            . '$full->unregister();'
            . '$shop = new \Composer\Autoload\ClassLoader();'
            . 'foreach ($full->getPrefixesPsr4() as $prefix => $paths) {'
            . '    if (!in_array($prefix, $library, true)) { $shop->addPsr4($prefix, $paths); }'
            . '}'
            . '$shop->addClassMap(array_filter($full->getClassMap(), static fn (string $class): bool => '
            . '    array_filter($library, static fn (string $prefix): bool => str_starts_with($class, $prefix)) === [], ARRAY_FILTER_USE_KEY));'
            . '$shop->register();'
            . $setup
            . $probe;

        $file = tempnam(sys_get_temp_dir(), 'rc-qr-probe');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $output = shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        } finally {
            unlink($file);
        }

        return trim((string) $output);
    }
}
