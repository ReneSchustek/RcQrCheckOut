<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hält fest, an welchem Block der Bestätigungsseite der QR-Code hängt.
 *
 * Einen Block, den es in der Kernvorlage nicht gibt, übergeht Twig ohne Meldung, und das Markup
 * erscheint nie. `page_checkout_finish_information` ist so ein Block, der verlockend klingt, im
 * Kern aber fehlt.
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

    public function testOverridesABlockThatExistsInCore(): void
    {
        // page_checkout_finish_details ist ein realer Block der Core-Finish-Seite.
        self::assertStringContainsString(
            '{% block page_checkout_finish_details %}',
            $this->finishTemplate(),
        );
    }

    public function testNoPhantomBlock(): void
    {
        // Diesen Block gibt es im Kern nicht, die Erweiterung wäre an ihm unsichtbar.
        self::assertStringNotContainsString(
            'page_checkout_finish_information',
            $this->finishTemplate(),
        );
    }

    public function testKeepsTheParentBlock(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->finishTemplate());
    }
}
