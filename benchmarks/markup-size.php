<?php

declare(strict_types=1);

/*
 * Gibt je Bildgröße die Länge des SVG-Markups aus, eine Zeile „Größe;Bytes;Bytes gepackt". Das Markup
 * steht ohne zweite Anfrage in der Abschlussseite und geht mit ihr an den Kunden; übertragen wird es
 * gepackt, deshalb steht die Größe nach gzip daneben.
 */

use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;

// Wie in phpbench.json: Hinweise auf veraltete Schreibweisen aus Fremdbibliotheken stünden sonst in der Ausgabe.
error_reporting(8191);

require __DIR__ . '/bootstrap.php';

$record = (new GiroCodeBuilder())->build('Musterhandel Nord GmbH', 'DE89370400440532013000', 'COBADEFFXXX', 1234.56, 'EUR', '10234', 'Bestellung {orderNumber}');
if ($record === null) {
    throw new RuntimeException('Kein Datensatz gebildet; die Messdaten passen nicht mehr zur EPC-Prüfung.');
}

$service = new QrCodeService();
foreach ([64, 200, 512, 1024, 2048] as $size) {
    $svg = $service->generateSvg($record, $size);
    echo $size, ';', \strlen($svg), ';', \strlen((string) gzencode($svg, 6)), \PHP_EOL;
}
