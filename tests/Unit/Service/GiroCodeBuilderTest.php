<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;

final class GiroCodeBuilderTest extends TestCase
{
    // Kanonische, gültige deutsche Beispiel-IBAN (besteht die Mod-97-Prüfung).
    private const VALID_IBAN = 'DE89370400440532013000';

    private GiroCodeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GiroCodeBuilder();
    }

    public function testBuildsCanonicalBcdDataset(): void
    {
        $result = $this->builder->build('Muster GmbH', self::VALID_IBAN, 'COBADEFFXXX', 1234.5, 'EUR', 'SW10001');

        self::assertNotNull($result);
        $lines = explode("\n", $result);

        self::assertSame('BCD', $lines[0]);
        self::assertSame('002', $lines[1]);
        self::assertSame('1', $lines[2]);
        self::assertSame('SCT', $lines[3]);
        self::assertSame('COBADEFFXXX', $lines[4]);
        self::assertSame('Muster GmbH', $lines[5]);
        self::assertSame(self::VALID_IBAN, $lines[6]);
        self::assertSame('EUR1234.50', $lines[7]);
        self::assertSame('', $lines[8]);
        self::assertSame('Bestellung SW10001', $lines[9]);
    }

    public function testBicIsOptional(): void
    {
        $result = $this->builder->build('Muster GmbH', self::VALID_IBAN, '', 10.0, 'EUR', 'SW1');

        self::assertNotNull($result);
        self::assertSame('', explode("\n", $result)[4]);
    }

    public function testPurposeTemplatePlaceholders(): void
    {
        $result = $this->builder->build('Shop', self::VALID_IBAN, '', 49.9, 'EUR', 'SW42', 'Zahlung {orderNumber} über {amount} EUR');

        self::assertNotNull($result);
        self::assertSame('Zahlung SW42 über 49,90 EUR', explode("\n", $result)[9]);
    }

    public function testDefaultPurposeWhenTemplateEmpty(): void
    {
        $result = $this->builder->build('Shop', self::VALID_IBAN, '', 5.0, 'EUR', 'SW7', '');

        self::assertNotNull($result);
        self::assertSame('Bestellung SW7', explode("\n", $result)[9]);
    }

    public function testAmountFormatting(): void
    {
        $result = $this->builder->build('Shop', self::VALID_IBAN, '', 7, 'EUR', 'SW1');
        self::assertNotNull($result);
        self::assertSame('EUR7.00', explode("\n", $result)[7]);
    }

    public function testRejectsNonEuroCurrency(): void
    {
        self::assertNull($this->builder->build('Shop', self::VALID_IBAN, '', 10.0, 'USD', 'SW1'));
        self::assertNull($this->builder->build('Shop', self::VALID_IBAN, '', 10.0, 'CHF', 'SW1'));
    }

    public function testRejectsInvalidIban(): void
    {
        // Falsche Prüfziffer / kaputte IBAN.
        self::assertNull($this->builder->build('Shop', 'DE00370400440532013000', '', 10.0, 'EUR', 'SW1'));
        self::assertNull($this->builder->build('Shop', 'NICHTEINEIBAN', '', 10.0, 'EUR', 'SW1'));
        self::assertNull($this->builder->build('Shop', '', '', 10.0, 'EUR', 'SW1'));
    }

    public function testAcceptsIbanWithSpacesAndLowercase(): void
    {
        $result = $this->builder->build('Shop', 'de89 3704 0044 0532 0130 00', '', 10.0, 'EUR', 'SW1');

        self::assertNotNull($result);
        self::assertSame(self::VALID_IBAN, explode("\n", $result)[6]);
    }

    public function testRejectsEmptyRecipient(): void
    {
        self::assertNull($this->builder->build('   ', self::VALID_IBAN, '', 10.0, 'EUR', 'SW1'));
    }

    public function testRejectsAmountOutOfRange(): void
    {
        self::assertNull($this->builder->build('Shop', self::VALID_IBAN, '', 0.0, 'EUR', 'SW1'));
        self::assertNull($this->builder->build('Shop', self::VALID_IBAN, '', -5.0, 'EUR', 'SW1'));
        self::assertNull($this->builder->build('Shop', self::VALID_IBAN, '', 1_000_000_000.0, 'EUR', 'SW1'));
    }

    public function testClampsRecipientAndRemittanceLength(): void
    {
        $longName = str_repeat('A', 100);
        $longPurpose = str_repeat('B', 200);

        $result = $this->builder->build($longName, self::VALID_IBAN, '', 10.0, 'EUR', 'SW1', $longPurpose);

        self::assertNotNull($result);
        $lines = explode("\n", $result);
        self::assertSame(70, mb_strlen($lines[5]));
        self::assertSame(140, mb_strlen($lines[9]));
    }

    /**
     * Was: Ein Datensatz, der die Byte-Grenze sprengt.
     * Warum: EPC069-12 begrenzt den **gesamten** Datensatz auf 331 Bytes. Begrenzt waren bisher
     *        nur die Einzelfelder, und zwar in Zeichen — ein Umlaut ist ein Zeichen und zwei
     *        Bytes. Im ungünstigsten Fall kamen knapp 500 Bytes heraus. Aufgefallen wäre das
     *        niemandem: Der Code wird erzeugt, angezeigt, und erst beim Scannen passiert nichts —
     *        während der Kunde das Handy schon an den Bildschirm hält.
     * Erwartet: Der Datensatz bleibt unter der Grenze.
     */
    public function testTheRecordStaysWithinTheByteLimit(): void
    {
        $result = (new GiroCodeBuilder())->build(
            str_repeat('Ä', 70),            // 70 Zeichen, 140 Bytes
            'DE02120300000000202051',
            'BYLADEM1001',
            1234.56,
            'EUR',
            '10001',
            str_repeat('Ü', 140),           // 140 Zeichen, 280 Bytes
        );

        self::assertNotNull($result);
        self::assertLessThanOrEqual(331, \strlen($result));
    }

    /**
     * Was: Der Verwendungszweck wird gekürzt, alles andere bleibt.
     * Warum: Empfänger, IBAN und Betrag sind Angaben, die stimmen müssen — an ihnen darf nicht
     *        gekürzt werden. Der Verwendungszweck ist das einzige elastische Feld.
     * Erwartet: IBAN und Betrag unverändert, Verwendungszweck kürzer als angefordert.
     */
    public function testOnlyTheRemittanceIsShortened(): void
    {
        $result = (new GiroCodeBuilder())->build(
            str_repeat('Ä', 70),
            'DE02120300000000202051',
            'BYLADEM1001',
            1234.56,
            'EUR',
            '10001',
            str_repeat('Ü', 140),
        );

        self::assertNotNull($result);
        $lines = explode("\n", $result);

        self::assertSame('DE02120300000000202051', $lines[6]);
        self::assertSame('EUR1234.56', $lines[7]);
        self::assertLessThan(140, mb_strlen($lines[9]));
    }

    /**
     * Was: Gekürzt wird an Zeichengrenzen.
     * Warum: Ein abgeschnittenes Mehrbyte-Zeichen ergibt ungültiges UTF-8 — der Datensatz wäre
     *        dann aus einem zweiten Grund kaputt, und diesmal auf eine Art, die schwerer zu
     *        finden ist.
     * Erwartet: gültiges UTF-8.
     */
    public function testShorteningKeepsValidUtf8(): void
    {
        $result = (new GiroCodeBuilder())->build(
            str_repeat('Ä', 70),
            'DE02120300000000202051',
            'BYLADEM1001',
            1234.56,
            'EUR',
            '10001',
            str_repeat('Ü', 140),
        );

        self::assertNotNull($result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * Was: Eine unsinnige BIC.
     * Warum: In Version 002 ist die BIC **optional**. Eine leere ist gültig, eine falsche nicht —
     *        eine ungültige Eingabe wegzulassen ist deshalb strikt besser, als sie mitzuschicken.
     *        Ein Tippfehler des Betreibers erzeugte sonst einen Datensatz, den strenge Apps
     *        zurückweisen.
     * Erwartet: Das BIC-Feld bleibt leer, der Rest steht.
     */
    public function testAnInvalidBicIsLeftOutInsteadOfPassedOn(): void
    {
        $result = (new GiroCodeBuilder())->build(
            'Trummer Edelstahl GmbH',
            'DE02120300000000202051',
            'TIPPFEHLER',
            10.00,
            'EUR',
            '10001',
        );

        self::assertNotNull($result);
        $lines = explode("\n", $result);

        self::assertSame('', $lines[4], 'eine ungültige BIC darf nicht in den Datensatz');
        self::assertSame('DE02120300000000202051', $lines[6]);
    }

    /**
     * Was: Eine gültige BIC.
     * Warum: Die Gegenprobe. Eine Prüfung, die alles verwirft, ist so schlecht wie keine.
     * Erwartet: Sie steht im Datensatz.
     */
    public function testAValidBicIsKept(): void
    {
        $result = (new GiroCodeBuilder())->build(
            'Trummer Edelstahl GmbH',
            'DE02120300000000202051',
            'BYLADEM1001',
            10.00,
            'EUR',
            '10001',
        );

        self::assertNotNull($result);
        self::assertSame('BYLADEM1001', explode("\n", $result)[4]);
    }

    /**
     * Was: Die achtstellige BIC-Form.
     * Warum: ISO 9362 erlaubt acht **oder** elf Stellen. Nur elf zu akzeptieren, verwürfe die
     *        Hälfte aller gültigen Eingaben.
     * Erwartet: bleibt erhalten.
     */
    public function testTheEightCharacterBicFormIsAccepted(): void
    {
        $result = (new GiroCodeBuilder())->build(
            'Trummer Edelstahl GmbH',
            'DE02120300000000202051',
            'BYLADEMM',
            10.00,
            'EUR',
            '10001',
        );

        self::assertNotNull($result);
        self::assertSame('BYLADEMM', explode("\n", $result)[4]);
    }
}
