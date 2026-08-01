<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;

final class GiroCodeBuilderTest extends TestCase
{
    // Kanonische, gueltige deutsche Beispiel-IBAN (besteht die Mod-97-Pruefung).
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
        $result = $this->builder->build('Shop', self::VALID_IBAN, '', 49.9, 'EUR', 'SW42', 'Zahlung {orderNumber} ueber {amount} EUR');

        self::assertNotNull($result);
        self::assertSame('Zahlung SW42 ueber 49,90 EUR', explode("\n", $result)[9]);
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
        // Falsche Pruefziffer / kaputte IBAN.
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
}
