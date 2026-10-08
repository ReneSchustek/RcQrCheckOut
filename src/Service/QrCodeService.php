<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;

/**
 * Zeichnet beliebige Daten als QR-Code, in dieser Erweiterung den GiroCode-Datensatz: als SVG für die
 * Abschlussseite, als PNG für die Bestellmail.
 *
 * Auf der Seite SVG, weil das Bild ohne zweite Anfrage in der Seite steht und bei jeder
 * Bildschirmdichte scharf bleibt. In der Mail PNG, weil Mailprogramme SVG nicht zuverlässig zeigen. Die Klasse ist nicht final, damit die Tests sie ersetzen können.
 */
class QrCodeService
{
    // 200 Pixel lassen sich vom Bildschirm bequem abscannen, ohne die Bestätigungsseite zu füllen.
    public const DEFAULT_SIZE = 200;
    // Unter 64 Pixeln werden die Module eines GiroCodes für eine Handykamera zu klein; über 1024 wäre
    // das Bild größer als jede Bestätigungsseite. Zeit und Markup hängen an der Größe kaum: rund 11 ms
    // und 53 KB (gepackt 3 KB) bei 64 wie bei 1024 Pixeln, gemessen in benchmarks/.
    private const MIN_SIZE = 64;
    private const MAX_SIZE = 1024;

    // In der Mail mindestens 200 Pixel: Ein Mailprogramm verkleinert Bilder gern, und eine Banking-App
    // soll den Code noch vom Bildschirm lesen. Die breitere Ruhezone, weil der Hintergrund der Mail
    // nicht feststeht; manche Programme setzen ihn dunkel.
    private const MAIL_MIN_SIZE = 200;
    private const MAIL_MARGIN = 16;

    /**
     * Liefert das vollständige SVG-Markup, das die Vorlage mit `|raw` einbettet. Das ist unbedenklich,
     * weil das Markup aus endroid/qr-code stammt und die Daten nur als Muster enthält, nicht als Text.
     */
    public function generateSvg(string $data, int $size = self::DEFAULT_SIZE): string
    {
        // Ein schmaler Rand, weil die Karte um den Code schon hell ist; ganz ohne Ruhezone finden
        // manche Scanner die Ecken nicht. Die acht Pixel sind gewählt, nicht gemessen.
        return $this->render(new SvgWriter(), $data, max(self::MIN_SIZE, min(self::MAX_SIZE, $size)), 8);
    }

    /**
     * Das PNG für die Bestellmail, eingebettet über eine Content-ID. Ein `data:`-Bild zeigen Gmail,
     * Outlook und viele Apps nicht an.
     */
    public function generatePng(string $data, int $size = self::DEFAULT_SIZE): string
    {
        return $this->render(new PngWriter(), $data, max(self::MAIL_MIN_SIZE, min(self::MAX_SIZE, $size)), self::MAIL_MARGIN);
    }

    private function render(WriterInterface $writer, string $data, int $size, int $margin): string
    {
        $result = Builder::create()
            ->writer($writer)
            ->data($data)
            ->encoding(new Encoding('UTF-8'))
            // Stufe M schreibt EPC069-12 für den GiroCode vor.
            ->errorCorrectionLevel(new ErrorCorrectionLevelMedium())
            ->size($size)
            ->margin($margin)
            ->build();

        return $result->getString();
    }
}
