<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

/**
 * Ein GiroCode zu einer Bestellung: der Datensatz, aus dem der QR-Code entsteht, und die Angaben, die
 * daneben als Text stehen, falls der Code nicht gelesen werden kann.
 *
 * Der Verwendungszweck stammt aus dem fertigen Datensatz, nicht aus der Vorlage. Kürzt der Builder ihn,
 * damit der Datensatz unter die Byte-Grenze passt, steht im Text dieselbe Fassung wie im Code.
 */
final readonly class GiroCodeDetails
{
    public function __construct(
        public string $payload,
        public string $recipient,
        public string $iban,
        public float $amount,
        public string $purpose,
        public int $size,
    ) {
    }
}
