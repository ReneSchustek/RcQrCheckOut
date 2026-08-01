<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Hängt auf der Bestellbestätigungs-Seite einen GiroCode (SEPA-Überweisung als QR) an die Page,
 * damit der Kunde ihn mit seiner Banking-App scannt und die Überweisung vorausgefüllt anstoesst.
 *
 * Erscheint nur, wenn: Plugin aktiv, die Zahlart der Bestellung in der konfigurierten Liste liegt,
 * eine IBAN gepflegt ist und die Bestellwährung EUR ist. Sonst fail-soft (kein QR, kein Fehler).
 */
class QrCodeSubscriber implements EventSubscriberInterface
{
    private const CONFIG_ENABLED = 'RcQrCheckOut.config.qrCodeEnabled';
    private const CONFIG_SIZE = 'RcQrCheckOut.config.qrCodeSize';
    private const CONFIG_IBAN = 'RcQrCheckOut.config.iban';
    private const CONFIG_BIC = 'RcQrCheckOut.config.bic';
    private const CONFIG_RECIPIENT = 'RcQrCheckOut.config.recipient';
    private const CONFIG_PURPOSE = 'RcQrCheckOut.config.purposeTemplate';
    private const CONFIG_PAYMENT_METHODS = 'RcQrCheckOut.config.paymentMethodIds';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly QrCodeService $qrCodeService,
        private readonly GiroCodeBuilder $giroCodeBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onFinishPageLoaded',
        ];
    }

    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        if (!$this->isEnabled($salesChannelId)) {
            return;
        }

        $order = $event->getPage()->getOrder();
        $orderId = $order->getId();

        // Zahlart der Bestellung ermitteln (primäre Transaktion, sonst jüngste) und gegen die
        // konfigurierte Zahlarten-Liste prüfen. Leere Liste => bewusst für keine Zahlart aktiv.
        $transaction = $order->getPrimaryOrderTransaction() ?? $order->getTransactions()?->last();
        $paymentMethodId = $transaction?->getPaymentMethodId();
        if ($paymentMethodId === null
            || !\in_array($paymentMethodId, $this->allowedPaymentMethodIds($salesChannelId), true)) {
            return;
        }

        $recipient = (string) $this->systemConfigService->getString(self::CONFIG_RECIPIENT, $salesChannelId);
        $iban = (string) $this->systemConfigService->getString(self::CONFIG_IBAN, $salesChannelId);

        try {
            $currencyIso = $order->getCurrency()?->getIsoCode()
                ?? $event->getSalesChannelContext()->getCurrency()->getIsoCode();

            $giroCode = $this->giroCodeBuilder->build(
                $recipient,
                $iban,
                (string) $this->systemConfigService->getString(self::CONFIG_BIC, $salesChannelId),
                $order->getAmountTotal(),
                $currencyIso,
                (string) $order->getOrderNumber(),
                (string) $this->systemConfigService->getString(self::CONFIG_PURPOSE, $salesChannelId),
            );

            // Kein bildbarer GiroCode (Fremdwährung, IBAN fehlt/ungültig, Betrag ungültig) => kein QR.
            if ($giroCode === null) {
                return;
            }

            $svg = $this->qrCodeService->generateSvg($giroCode, $this->getSize($salesChannelId));
        } catch (Throwable $exception) {
            // Fail-soft: der QR-Code ist dekorativ. Ein Fehler darf die bereits abgeschlossene
            // Bestellbestätigung niemals mit einem 500 abreissen — nur loggen.
            $this->logger->error('RcQrCheckOut: GiroCode-Generierung fehlgeschlagen', [
                'orderId' => $orderId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $event->getPage()->addExtension('rcQrCheckOut', new ArrayStruct([
            'enabled' => true,
            'svg' => $svg,
            'recipient' => $recipient,
            'iban' => $iban,
            'amount' => $order->getAmountTotal(),
        ]));
    }

    private function isEnabled(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::CONFIG_ENABLED, $salesChannelId);

        // Default: true (auch bei null oder fehlender Konfiguration).
        return $value === null ? true : (bool) $value;
    }

    /**
     * @return list<string>
     */
    private function allowedPaymentMethodIds(?string $salesChannelId): array
    {
        $value = $this->systemConfigService->get(self::CONFIG_PAYMENT_METHODS, $salesChannelId);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $id): bool => \is_string($id) && $id !== ''));
    }

    private function getSize(?string $salesChannelId): int
    {
        $value = $this->systemConfigService->get(self::CONFIG_SIZE, $salesChannelId);

        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return QrCodeService::DEFAULT_SIZE;
    }
}
