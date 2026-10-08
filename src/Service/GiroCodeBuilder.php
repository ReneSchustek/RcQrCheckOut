<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

/**
 * Baut den GiroCode-Datensatz nach EPC069-12 („BCD"), den deutsche Banking-Apps als
 * SEPA-Überweisung einlesen. Die Klasse hat keine Seiteneffekte und prüft selbst alles, was der
 * Standard verlangt: IBAN nach Mod-97, Währung EUR, Feldlängen und Betragsspanne.
 *
 * Der Datensatz besteht aus zehn durch LF getrennten Zeilen mit höchstens 331 Bytes insgesamt, in
 * dieser Reihenfolge: BCD, Version 002, Zeichensatz 1 (UTF-8), SCT, BIC (optional), Empfänger,
 * IBAN, EUR mit Betrag, Purpose-Code (leer) und unstrukturierter Verwendungszweck.
 */
final class GiroCodeBuilder
{
    private const SERVICE_TAG = 'BCD';
    private const VERSION = '002';           // 002 = BIC optional
    private const CHARSET = '1';             // 1 = UTF-8
    private const IDENTIFICATION = 'SCT';    // SEPA Credit Transfer
    // Feldgrenzen in Zeichen und Betragsspanne gibt EPC069-12 vor, eine eigene Wahl steckt nicht darin.
    private const MAX_NAME = 70;
    private const MAX_REMITTANCE = 140;
    private const MIN_AMOUNT = 0.01;
    private const MAX_AMOUNT = 999999999.99;
    private const DEFAULT_PURPOSE_TEMPLATE = 'Bestellung {orderNumber}';

    /**
     * Höchstlänge des gesamten Datensatzes nach EPC069-12, gezählt in Bytes und nicht in Zeichen.
     *
     * Ein Umlaut zählt als ein Zeichen und belegt in UTF-8 zwei Bytes. Mit Umlauten lassen die
     * Feldgrenzen von 70 und 140 Zeichen allein knapp 500 Bytes zu, mit Zeichen aus vier Bytes
     * noch mehr. Die App bekommt dann einen Datensatz, den sie zurückweisen darf.
     */
    private const MAX_PAYLOAD_BYTES = 331;

    /**
     * ISO 9362: acht oder elf Stellen, festes Muster.
     *
     * In Version 002 ist die BIC optional. Eine leere ist gültig, eine falsche nicht, deshalb ist
     * es besser, eine ungültige Eingabe wegzulassen, als sie mitzuschicken.
     */
    private const BIC_PATTERN = '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    /**
     * Liefert den fertigen BCD-Datensatz oder null, wenn sich nach EPC-Regeln kein GiroCode bilden
     * lässt: bei Fremdwährung, fehlender oder ungültiger IBAN, fehlendem Empfänger, einem Betrag
     * außerhalb von 0,01 bis 999.999.999,99 oder einem Datensatz, der auch mit leerem
     * Verwendungszweck über 331 Bytes läge. Der Aufrufer lässt den QR-Code dann ohne Fehler weg.
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
            // Ohne BIC liest die App den Datensatz, mit einer falschen nicht. Version 002 erlaubt das leere Feld.
            $bic = '';
        }

        $purpose = $this->renderPurpose($purposeTemplate, $orderNumber, $amount);

        $lines = [
            self::SERVICE_TAG,
            self::VERSION,
            self::CHARSET,
            self::IDENTIFICATION,
            $bic,                                          // in Version 002 optional, darf leer sein
            $this->clamp($recipient, self::MAX_NAME),
            $iban,
            'EUR' . number_format($amount, 2, '.', ''),    // Punkt als Dezimaltrenner, ohne Tausender: EUR1234.50
            '',                                            // Purpose-Code, für einen Shop ohne Bedeutung
            $this->clamp($purpose, self::MAX_REMITTANCE),  // unstrukturierter Verwendungszweck
        ];

        return $this->fitToPayloadLimit($lines);
    }

    /**
     * Hält den Datensatz unter der Byte-Grenze, notfalls mit einem kürzeren Verwendungszweck.
     *
     * Der Verwendungszweck ist das einzige Feld, das nachgeben darf, denn Empfänger, IBAN und Betrag
     * müssen stimmen. Passt es auch mit leerem Verwendungszweck nicht, gibt es keinen Code. Ein
     * fehlender QR-Code ist ehrlicher als einer, der beim Scannen nichts tut, während der Kunde das
     * Handy schon an den Bildschirm hält.
     *
     * Der Verwendungszweck muss die letzte Zeile sein.
     *
     * @param list<string> $lines
     */
    private function fitToPayloadLimit(array $lines): ?string
    {
        $purposeIndex = \count($lines) - 1;
        $purpose = $lines[$purposeIndex];

        // Zeichen für Zeichen kürzen und jedes Mal nachmessen. Eine Rechnung mit Byte-Differenzen
        // wäre schneller und hätte genau die Sorte Fehler, die niemand bemerkt, weil der Datensatz
        // dann still einen Hauch zu lang wäre. Gekürzt wird an Zeichengrenzen, denn ein
        // abgeschnittenes Mehrbyte-Zeichen ergäbe ungültiges UTF-8.
        for ($length = mb_strlen($purpose); $length >= 0; --$length) {
            $lines[$purposeIndex] = mb_substr($purpose, 0, $length);
            $payload = implode("\n", $lines);

            if (\strlen($payload) <= self::MAX_PAYLOAD_BYTES) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * Im Verwendungszweck steht der Betrag in deutscher Schreibweise, weil ihn dort der Kunde liest.
     * Das Betragsfeld trägt ihn in der Form, die der Standard verlangt.
     */
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
     * Prüft Form und Prüfziffer nach ISO 7064 (Mod-97). Ob das Konto bei einer Bank existiert,
     * lässt sich daraus nicht ablesen.
     */
    private function isValidIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
            return false;
        }

        // 15 Stellen hat die kürzeste IBAN (Norwegen), 34 erlaubt ISO 13616 höchstens.
        $length = \strlen($iban);
        if ($length < 15 || $length > 34) {
            return false;
        }

        // Die ersten vier Zeichen wandern ans Ende, Buchstaben werden zu Zahlen (A=10 bis Z=35),
        // und der Rest modulo 97 muss 1 ergeben.
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }

        // Ziffer für Ziffer, weil die Zahl mit bis zu 68 Stellen in keinen Integer passt.
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
