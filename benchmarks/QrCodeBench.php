<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Benchmarks;

use PhpBench\Attributes as Bench;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use RuntimeException;

/**
 * Das Zeichnen des GiroCodes, die einzige Stelle der Erweiterung, die spürbar Zeit kostet.
 *
 * Es läuft bei jedem Aufruf der Abschlussseite einer Vorkasse-Bestellung, während der Kunde auf die
 * Seite wartet; zwischengespeichert wird nichts. Gemessen werden der übliche Datensatz bei den
 * Größen 64, 200 und 1024 Pixel (untere Grenze, Vorgabe, obere Grenze) und der längste Datensatz,
 * den die EPC-Regel erlaubt: Er braucht die meisten Module. Der Datensatz selbst (`GiroCodeBuilder`)
 * ist Zeichenkettenarbeit und wird nur zum Vergleich mitgemessen.
 */
#[Bench\Revs(5)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class QrCodeBench
{
    private QrCodeService $service;

    private string $typical;

    private string $longest;

    public function setUp(): void
    {
        $builder = new GiroCodeBuilder();
        $this->service = new QrCodeService();

        $typical = $builder->build('Musterhandel Nord GmbH', 'DE89370400440532013000', 'COBADEFFXXX', 1234.56, 'EUR', '10234', 'Bestellung {orderNumber}');
        $longest = $builder->build(str_repeat('Ä', 70), 'DE89370400440532013000', 'COBADEFFXXX', 999999999.99, 'EUR', '10234', str_repeat('Ö', 140));
        if ($typical === null || $longest === null) {
            throw new RuntimeException('Kein Datensatz gebildet; die Messdaten passen nicht mehr zur EPC-Prüfung.');
        }

        $this->typical = $typical;
        $this->longest = $longest;
    }

    /**
     * @param array{size: int} $params
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\ParamProviders('sizes')]
    public function benchTypicalGiroCode(array $params): void
    {
        $this->service->generateSvg($this->typical, $params['size']);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchLongestGiroCode(): void
    {
        $this->service->generateSvg($this->longest);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchBuildRecord(): void
    {
        (new GiroCodeBuilder())->build('Musterhandel Nord GmbH', 'DE89370400440532013000', 'COBADEFFXXX', 1234.56, 'EUR', '10234', 'Bestellung {orderNumber}');
    }

    /**
     * @return array<string, array{size: int}>
     */
    public function sizes(): array
    {
        return [
            '64 px' => ['size' => 64],
            '200 px' => ['size' => 200],
            '1024 px' => ['size' => 1024],
        ];
    }
}
