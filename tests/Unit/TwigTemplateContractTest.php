<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Injektionsstelle des QR-Codes auf der Finish-Seite fest. Ein
 * `sw_extends`-Block, den es im Ziel-Template nicht gibt, wird von Twig
 * stillschweigend ignoriert -- das Markup rendert dann nie (genau dieser Defekt
 * trat auf: `page_checkout_finish_information` existiert im Core nicht).
 * Dieser Test verhindert einen Rückfall auf einen Phantom-Block.
 */
final class TwigTemplateContractTest extends TestCase
{
    private function finishTemplate(): string
    {
        $path = __DIR__ . '/../../src/Resources/views/storefront/page/checkout/finish/index.html.twig';
        $content = file_get_contents($path);
        self::assertIsString($content, 'Finish-Template nicht lesbar: ' . $path);

        return $content;
    }

    public function testExtendsTheCoreFinishPage(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/page/checkout/finish/index.html.twig' %}",
            $this->finishTemplate(),
        );
    }

    public function testUeberschreibtEinenImCoreExistierendenBlock(): void
    {
        // page_checkout_finish_details ist ein realer Block der Core-Finish-Seite.
        self::assertStringContainsString(
            '{% block page_checkout_finish_details %}',
            $this->finishTemplate(),
        );
    }

    public function testNoPhantomBlock(): void
    {
        // Der früher genutzte Block existiert im Core nicht -> darf nie zurückkehren.
        self::assertStringNotContainsString(
            'page_checkout_finish_information',
            $this->finishTemplate(),
        );
    }

    public function testParentBleibtErhalten(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->finishTemplate());
    }
}
