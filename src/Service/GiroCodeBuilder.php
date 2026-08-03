<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

/**
 * Baut den GiroCode-Datensatz nach EPC069-12 („BCD"), den deutsche Banking-Apps als
 * SEPA-Überweisung einlesen. Reiner, seiteneffektfreier Builder — die Validierung
 * (IBAN Mod-97, EUR-Pflicht, Längen, Betragsformat) ist vollständig gekapselt.
 *
 * Der Datensatz besteht aus LF-getrennten Zeilen (max. 331 Bytes gesamt):
 *   BCD / Version 002 / Zeichensatz 1 (UTF-8) / SCT / BIC(optional) / Empfänger /
 *   IBAN / EUR<Betrag> / Purpose-Code(leer) / Verwendungszweck(unstrukturiert).
 */
final class GiroCodeBuilder
{
    private const SERVICE_TAG = 'BCD';
    private const VERSION = '002';           // 002 = BIC optional
    private const CHARSET = '1';             // 1 = UTF-8
    private const IDENTIFICATION = 'SCT';    // SEPA Credit Transfer
    private const MAX_NAME = 70;
    private const MAX_REMITTANCE = 140;
    private const MIN_AMOUNT = 0.01;
    private const MAX_AMOUNT = 999999999.99;
    private const DEFAULT_PURPOSE_TEMPLATE = 'Bestellung {orderNumber}';

    /**
     * Höchstlänge des gesamten Datensatzes nach EPC069-12 — in **Bytes**, nicht in Zeichen.
     *
     * Der Unterschied ist der ganze Punkt: Ein Umlaut zählt als ein Zeichen und belegt in UTF-8
     * zwei Bytes. Die Feldgrenzen (70 / 140 Zeichen) allein lassen im ungünstigsten Fall knapp
     * 500 Bytes zu — die App bekommt dann einen Datensatz, den sie zurückweisen darf.
     */
    private const MAX_PAYLOAD_BYTES = 331;

    /**
     * ISO 9362: acht oder elf Stellen, festes Muster.
     *
     * In Version 002 ist die BIC **optional**. Eine leere ist gültig, eine falsche nicht —
     * eine ungültige Eingabe wegzulassen ist deshalb strikt besser, als sie mitzuschicken.
     */
    private const BIC_PATTERN = '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    /**
     * Gibt den fertigen BCD-Datensatz zurück — oder null, wenn ein GiroCode nach EPC-Regeln
     * nicht bildbar ist (Fremdwährung, fehlende/ungültige IBAN, fehlender Empfänger,
     * Betrag ausserhalb 0,01–999.999.999,99). Der Aufrufer lässt dann fail-soft den QR weg.
     */
    public function build(
        string $recipient,
        string $iban,
        string $bic,
        float $amount,
        string $currencyIso,
        string $orderNumber,
        string $purposeTemplate = '',
    ): ?string {
        $recipient = trim($recipient);
        $iban = $this->normalize($iban);
        $bic = $this->normalize($bic);

        // GiroCode unterstützt ausschließlich EUR.
        if (strtoupper(trim($currencyIso)) !== 'EUR') {
            return null;
        }
        if ($recipient === '' || $iban === '' || !$this->isValidIban($iban)) {
            return null;
        }
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            return null;
        }

        if ($bic !== '' && preg_match(self::BIC_PATTERN, $bic) !== 1) {
            // Lieber ohne als mit einer falschen: Version 002 erlaubt das leere Feld.
            $bic = '';
        }

        $purpose = $this->renderPurpose($purposeTemplate, $orderNumber, $amount);

        $lines = [
            self::SERVICE_TAG,
            self::VERSION,
            self::CHARSET,
            self::IDENTIFICATION,
            $bic,                                          // in Version 002 optional (leer erlaubt)
            $this->clamp($recipient, self::MAX_NAME),
            $iban,
            'EUR' . number_format($amount, 2, '.', ''),    // z. B. EUR1234.50
            '',                                            // Purpose-Code (ungenutzt)
            $this->clamp($purpose, self::MAX_REMITTANCE),  // unstrukturierter Verwendungszweck
        ];

        return $this->fitToPayloadLimit($lines);
    }

    /**
     * Hält den Datensatz unter der Byte-Grenze — notfalls, indem der Verwendungszweck kürzer wird.
     *
     * Der Verwendungszweck ist das einzige elastische Feld: Empfänger, IBAN und Betrag sind
     * Angaben, die stimmen müssen. Passt es auch dann nicht, gibt es keinen Code. Ein fehlender
     * QR ist ehrlicher als einer, der beim Scannen nichts tut — da hält der Kunde das Handy
     * schon an den Bildschirm.
     *
     * @param list<string> $lines
     */
    private function fitToPayloadLimit(array $lines): ?string
    {
        $purposeIndex = \count($lines) - 1;
        $purpose = $lines[$purposeIndex];

        // Geradeaus statt schlau: Zeichen für Zeichen kürzen und jedes Mal nachmessen. Eine
        // Rechnung mit Byte-Differenzen wäre schneller und hätte genau die Sorte Fehler, die
        // hier niemand bemerkt — der Datensatz wäre dann still einen Hauch zu lang. Gekürzt wird
        // an Zeichengrenzen: Ein abgeschnittenes Mehrbyte-Zeichen ergäbe ungültiges UTF-8.
        for ($length = mb_strlen($purpose); $length >= 0; --$length) {
            $lines[$purposeIndex] = mb_substr($purpose, 0, $length);
            $payload = implode("\n", $lines);

            if (\strlen($payload) <= self::MAX_PAYLOAD_BYTES) {
                return $payload;
            }
        }

        return null;
    }

    private function renderPurpose(string $template, string $orderNumber, float $amount): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = self::DEFAULT_PURPOSE_TEMPLATE;
        }

        return strtr($template, [
            '{orderNumber}' => $orderNumber,
            '{amount}' => number_format($amount, 2, ',', '.'),
        ]);
    }

    private function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    }

    /**
     * Format-Plausibilität + ISO-7064-Mod-97-Prüfung (keine Bank-Existenzprüfung).
     */
    private function isValidIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
            return false;
        }

        $length = \strlen($iban);
        if ($length < 15 || $length > 34) {
            return false;
        }

        // Erste vier Zeichen ans Ende, Buchstaben -> Zahlen (A=10 … Z=35), dann mod 97 == 1.
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private function clamp(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
