<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeService
{
    public const DEFAULT_SIZE = 200;
    private const MIN_SIZE = 64;
    private const MAX_SIZE = 1024;

    /**
     * Erzeugt ein QR-Code-SVG für die übergebenen Daten (hier: der GiroCode-BCD-Datensatz).
     * Rückgabe ist das vollständige SVG-Markup, inline einbettbar in Twig (via `|raw` — der
     * Inhalt kommt ausschließlich aus endroid/qr-code, kein User-Input im Render-Pfad).
     */
    public function generateSvg(string $data, int $size = self::DEFAULT_SIZE): string
    {
        $clampedSize = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));

        $result = Builder::create()
            ->writer(new SvgWriter())
            ->data($data)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelMedium())
            ->size($clampedSize)
            ->margin(8)
            ->build();

        return $result->getString();
    }
}
