<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;

/**
 * Sichert ab, dass QrCodeService gültiges SVG liefert und die Kantenlänge auf 64 bis 1024 Pixel
 * begrenzt. Die Breite prüfen die Tests nur als Spanne, weil die Bibliothek den Rand hinzurechnet
 * und auf ganze Module rundet.
 */
final class QrCodeServiceTest extends TestCase
{
    public function testGenerateSvgForUrlReturnsValidSvg(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com/order/1234');

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    public function testGenerateSvgUsesDefaultSizeWhenNotProvided(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com');

        // Die Vorgabe von 200 Pixeln plus Rand ergibt eine Breite zwischen 200 und 299.
        self::assertMatchesRegularExpression('/width="(2[0-9]{2})px?"/', $svg);
    }

    public function testGenerateSvgClampsTooSmallSize(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com', 16);

        // Kleinere Werte steigen auf 64 Pixel, dazu kommt der Rand.
        self::assertMatchesRegularExpression('/width="(6[4-9]|[789][0-9]|1[0-2][0-9])px?"/', $svg);
    }

    public function testGenerateSvgClampsTooLargeSize(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com', 5000);

        // Größere Werte sinken auf 1024 Pixel, mit Rand bleibt die Breite unter 1100.
        self::assertMatchesRegularExpression('/width="10[0-9]{2}px?"/', $svg);
    }

    public function testGenerateSvgWithUmlautInUrlEncodesCorrectly(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com/bestellung/änderung');

        // Ein Umlaut in den Daten darf keine Ausnahme auslösen, weil der Code als UTF-8 kodiert.
        self::assertNotEmpty($svg);
    }

    /**
     * Wächter aus dem Messprojekt (benchmarks/): Gezeichnet wird bei jedem Aufruf der Abschlussseite,
     * während der Kunde wartet. Gemessen sind 35 ms für den längsten erlaubten Datensatz; die Grenze
     * ist großzügig, damit sie nur bei einem echten Rückschritt anschlägt.
     */
    public function testTheLongestGiroCodeIsDrawnQuickly(): void
    {
        $service = new QrCodeService();
        $data = implode("\n", ['BCD', '002', '1', 'SCT', 'COBADEFFXXX', str_repeat('Ä', 70), 'DE89370400440532013000', 'EUR999999999.99', '', '', str_repeat('Ö', 140)]);
        $service->generateSvg('warm');

        $start = hrtime(true);
        $service->generateSvg($data);
        $milliseconds = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(500, $milliseconds, 'Das Zeichnen des GiroCodes ist um ein Vielfaches langsamer geworden.');
    }

    /**
     * Das Markup steht in der Seite. Gemessen sind rund 53 KB bei jeder Größe; wächst es deutlich,
     * hat sich am Zeichnen etwas geändert, das die Seite schwerer macht.
     */
    public function testTheMarkupStaysSmallAtEverySize(): void
    {
        $service = new QrCodeService();
        $data = implode("\n", ['BCD', '002', '1', 'SCT', '', 'Musterhandel Nord GmbH', 'DE89370400440532013000', 'EUR1234.56', '', '', 'Bestellung 10234']);

        foreach ([64, 200, 1024] as $size) {
            self::assertLessThan(100_000, \strlen($service->generateSvg($data, $size)), "Markup bei {$size} px");
        }
    }
}
