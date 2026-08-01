<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;

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

        // Default-Size (200) plus Margin produziert SVG-width im Bereich 200-280
        self::assertMatchesRegularExpression('/width="(2[0-9]{2})px?"/', $svg);
    }

    public function testGenerateSvgClampsTooSmallSize(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com', 16);

        // Erwartung: kleine Werte werden auf 64 hochgeclampt + Margin
        self::assertMatchesRegularExpression('/width="(6[4-9]|[789][0-9]|1[0-2][0-9])px?"/', $svg);
    }

    public function testGenerateSvgClampsTooLargeSize(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com', 5000);

        // Erwartung: große Werte werden auf 1024 heruntergeclampt + Margin (final < 1100)
        self::assertMatchesRegularExpression('/width="10[0-9]{2}px?"/', $svg);
    }

    public function testGenerateSvgWithUmlautInUrlEncodesCorrectly(): void
    {
        $service = new QrCodeService();
        $svg = $service->generateSvg('https://example.com/bestellung/änderung');

        // SVG wird ohne Exception erzeugt — UTF-8-Encoding greift
        self::assertNotEmpty($svg);
    }
}
