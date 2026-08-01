<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use Ruhrcoder\RcQrCheckOut\Subscriber\QrCodeSubscriber;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

final class QrCodeSubscriberTest extends TestCase
{
    private const VALID_IBAN = 'DE89370400440532013000';
    private const PM_ALLOWED = 'pm-vorkasse';

    public function testGetSubscribedEventsReturnsFinishPageEvent(): void
    {
        $events = QrCodeSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutFinishPageLoadedEvent::class, $events);
        self::assertSame('onFinishPageLoaded', $events[CheckoutFinishPageLoadedEvent::class]);
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->expects($this->never())->method('generateSvg');

        $subscriber = new QrCodeSubscriber($this->config(enabled: false), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent();

        $subscriber->onFinishPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcQrCheckOut'));
    }

    public function testDoesNothingWhenPaymentMethodNotSelected(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->expects($this->never())->method('generateSvg');

        // Order-Zahlart weicht von der konfigurierten Liste ab.
        $subscriber = new QrCodeSubscriber($this->config(), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent(paymentMethodId: 'pm-kreditkarte');

        $subscriber->onFinishPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcQrCheckOut'));
    }

    public function testDoesNothingForNonEuroOrder(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->expects($this->never())->method('generateSvg');

        $subscriber = new QrCodeSubscriber($this->config(), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent(currencyIso: 'USD');

        $subscriber->onFinishPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcQrCheckOut'));
    }

    public function testDoesNothingWhenIbanMissing(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->expects($this->never())->method('generateSvg');

        $subscriber = new QrCodeSubscriber($this->config(iban: ''), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent();

        $subscriber->onFinishPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcQrCheckOut'));
    }

    public function testAddsGiroCodeExtensionWhenAllConditionsMet(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->expects($this->once())
            ->method('generateSvg')
            ->with($this->stringContains('BCD'), 300)
            ->willReturn('<svg>giro</svg>');

        $subscriber = new QrCodeSubscriber($this->config(size: 300), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent(amount: 49.9);

        $subscriber->onFinishPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcQrCheckOut'));
        /** @var ArrayStruct $ext */
        $ext = $event->getPage()->getExtension('rcQrCheckOut');
        self::assertTrue($ext->get('enabled'));
        self::assertSame('<svg>giro</svg>', $ext->get('svg'));
        self::assertSame('Muster GmbH', $ext->get('recipient'));
        self::assertSame(self::VALID_IBAN, $ext->get('iban'));
        self::assertSame(49.9, $ext->get('amount'));
    }

    public function testFailsSoftWhenQrGenerationThrows(): void
    {
        $qrService = $this->createMock(QrCodeService::class);
        $qrService->method('generateSvg')->willThrowException(new RuntimeException('endroid kaputt'));

        $subscriber = new QrCodeSubscriber($this->config(), $qrService, new GiroCodeBuilder(), new NullLogger());
        $event = $this->createEvent();

        // Darf nicht werfen — Finish-Page bleibt heil, nur keine QR-Extension.
        $subscriber->onFinishPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcQrCheckOut'));
    }

    private function config(
        bool $enabled = true,
        int $size = 200,
        string $iban = self::VALID_IBAN,
    ): SystemConfigService {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['RcQrCheckOut.config.qrCodeEnabled', 'sc-id', $enabled],
            ['RcQrCheckOut.config.qrCodeSize', 'sc-id', $size],
            ['RcQrCheckOut.config.paymentMethodIds', 'sc-id', [self::PM_ALLOWED]],
        ]);
        $systemConfig->method('getString')->willReturnMap([
            ['RcQrCheckOut.config.recipient', 'sc-id', 'Muster GmbH'],
            ['RcQrCheckOut.config.iban', 'sc-id', $iban],
            ['RcQrCheckOut.config.bic', 'sc-id', ''],
            ['RcQrCheckOut.config.purposeTemplate', 'sc-id', ''],
        ]);

        return $systemConfig;
    }

    private function createEvent(
        string $paymentMethodId = self::PM_ALLOWED,
        string $currencyIso = 'EUR',
        float $amount = 19.99,
    ): CheckoutFinishPageLoadedEvent {
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getPaymentMethodId')->willReturn($paymentMethodId);

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn($currencyIso);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn('order-1');
        $order->method('getPrimaryOrderTransaction')->willReturn($transaction);
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getAmountTotal')->willReturn($amount);
        $order->method('getOrderNumber')->willReturn('SW10001');

        $page = new CheckoutFinishPage();
        $page->setOrder($order);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getCurrency')->willReturn($currency);

        return new CheckoutFinishPageLoadedEvent($page, $salesChannelContext, new Request());
    }
}
