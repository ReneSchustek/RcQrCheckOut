<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Entscheidet, ob eine Bestellung einen GiroCode bekommt, und bildet ihn.
 *
 * Eine Quelle für Abschlussseite und Bestellmail: Beide sollen denselben Code zeigen, und eine zweite
 * Fassung der Bedingungen liefe mit der Zeit auseinander. Ein Code entsteht nur, wenn er eingeschaltet
 * ist, die Zahlart der Bestellung in der eingestellten Liste steht und sich aus Empfänger, IBAN, Betrag
 * und der Währung EUR ein gültiger Datensatz bilden lässt.
 */
class GiroCodeResolver
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
        private readonly GiroCodeBuilder $giroCodeBuilder,
    ) {
    }

    /**
     * @param string $fallbackCurrencyIso die Währung des Kontexts, falls die Bestellung ihre nicht mitgeladen hat
     */
    public function resolve(OrderEntity $order, ?string $salesChannelId, string $fallbackCurrencyIso): ?GiroCodeDetails
    {
        if (!$this->isEnabled($salesChannelId)) {
            return null;
        }

        // Maßgeblich ist die primäre Transaktion, ohne sie die letzte der Liste. Eine leere
        // Zahlartenliste schaltet den Code für alle Zahlarten ab: Er ergibt nur bei Vorkasse und
        // ähnlichen Zahlarten Sinn, und die soll der Betreiber ausdrücklich wählen.
        $transaction = $order->getPrimaryOrderTransaction() ?? $order->getTransactions()?->last();
        $paymentMethodId = $transaction?->getPaymentMethodId();
        if ($paymentMethodId === null
            || !\in_array($paymentMethodId, $this->allowedPaymentMethodIds($salesChannelId), true)) {
            return null;
        }

        $recipient = $this->systemConfigService->getString(self::CONFIG_RECIPIENT, $salesChannelId);
        $iban = $this->systemConfigService->getString(self::CONFIG_IBAN, $salesChannelId);

        $payload = $this->giroCodeBuilder->build(
            $recipient,
            $iban,
            $this->systemConfigService->getString(self::CONFIG_BIC, $salesChannelId),
            $order->getAmountTotal(),
            $order->getCurrency()?->getIsoCode() ?? $fallbackCurrencyIso,
            (string) $order->getOrderNumber(),
            $this->systemConfigService->getString(self::CONFIG_PURPOSE, $salesChannelId),
        );

        // Ohne gültigen Datensatz (Fremdwährung, Empfänger oder IBAN fehlt, Betrag außerhalb der
        // Spanne) gibt es keinen Code.
        if ($payload === null) {
            return null;
        }

        $lines = explode("\n", $payload);

        return new GiroCodeDetails(
            $payload,
            $recipient,
            $iban,
            $order->getAmountTotal(),
            // Der Verwendungszweck ist die letzte Zeile des Datensatzes, so wie der Builder ihn ablegt.
            (string) end($lines),
            $this->size($salesChannelId),
        );
    }

    private function isEnabled(?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::CONFIG_ENABLED, $salesChannelId);

        // Ohne gespeicherten Wert gilt dieselbe Vorgabe wie in config.xml: eingeschaltet.
        if ($value === null) {
            return true;
        }

        // Über die Konsole ohne `--json` gesetzt, steht „false" als Zeichenkette in der Datenbank, und
        // `(bool) 'false'` wäre wahr. Unlesbares gilt als aus: Ein Zahlcode ohne klaren Auftrag ist
        // schlimmer als keiner.
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Leere Einträge und alles außer Zeichenketten fallen heraus, damit ein beschädigter Wert keine
     * Zahlart freischaltet.
     *
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

    private function size(?string $salesChannelId): int
    {
        $value = $this->systemConfigService->get(self::CONFIG_SIZE, $salesChannelId);

        // Je nach Speicherweg kommt die Zahl als int oder als Ziffernfolge an. Die Grenzen setzt
        // QrCodeService.
        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return QrCodeService::DEFAULT_SIZE;
    }
}
