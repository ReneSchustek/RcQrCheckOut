<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeResolver;
use Ruhrcoder\RcQrCheckOut\Service\MailTemplateTypeLookup;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeSentEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Defaults;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Bringt den GiroCode der Abschlussseite auch in die Bestellbestätigung per Mail.
 *
 * Zwei Schritte, weil die Mail dazwischen gerendert wird: Vor dem Prüfen der Mail stellt der Subscriber
 * die Variable `rcGiroCode` bereit (Content-ID, Empfänger, IBAN, Betrag, Verwendungszweck); die
 * Mailvorlage zeigt den Block nur, wenn es sie gibt. Vor dem Versand bettet er das PNG unter der
 * Content-ID ein. Ein `data:`-Bild zeigen Gmail, Outlook und viele Apps nicht an, ein eingebettetes Bild
 * mit Content-ID zeigen sie im Text.
 *
 * Das Bild kommt nur in die Mail, wenn die gerenderte Vorlage `cid:girocode` auch verwendet. Eine
 * Vorlage ohne Platzhalter bekäme sonst einen losen Anhang, mit dem der Kunde nichts anfangen kann.
 *
 * Das PNG wartet zwischen den beiden Ereignissen in diesem Dienst, nicht in den Vorlagendaten: Die
 * schreibt Shopware ins Protokoll, und dort hätte ein Bild nichts verloren. Beide Ereignisse laufen in
 * `MailService::send()` direkt nacheinander; `reset()` räumt zwischen zwei Anfragen eines langlebigen
 * Arbeitsprozesses auf.
 */
final class OrderMailGiroCodeSubscriber implements EventSubscriberInterface, ResetInterface
{
    public const CONTENT_ID = 'girocode';
    public const TEMPLATE_VARIABLE = 'rcGiroCode';

    // Die Mails, in denen der Kunde noch bezahlen soll. „Bestellung erneut senden" in der Verwaltung
    // nimmt dieselbe Vorlage; eine eigene Zahlungserinnerung kennt der Kern nicht.
    private const TEMPLATE_TYPES = ['order_confirmation_mail'];

    private ?string $pendingPng = null;

    public function __construct(
        private readonly GiroCodeResolver $giroCodeResolver,
        private readonly QrCodeService $qrCodeService,
        private readonly MailTemplateTypeLookup $templateTypeLookup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onBeforeValidate',
            MailBeforeSentEvent::class => 'onBeforeSent',
        ];
    }

    public function onBeforeValidate(MailBeforeValidateEvent $event): void
    {
        // Ein Rest aus einer vorigen Mail, deren Versand gescheitert ist, darf nicht an diese gehen.
        $this->pendingPng = null;

        $order = $event->getTemplateData()['order'] ?? null;
        $templateId = $event->getData()['templateId'] ?? null;
        if (!$order instanceof OrderEntity || !\is_string($templateId) || !$this->isPaymentMail($templateId)) {
            return;
        }

        // Ist die Zahlung schon eingegangen, etwa beim erneuten Senden aus der Verwaltung, hätte ein
        // Überweisungscode nur Verwirrung zur Folge. Ohne geladenen Zustand gilt die Bestellung als offen.
        // Der Flow lädt `primaryOrderTransaction` ohne Zustand und den Zustand nur an `transactions`
        // (`OrderProvider`); deshalb wird er dort nachgeschlagen.
        $transaction = $order->getPrimaryOrderTransaction() ?? $order->getTransactions()?->last();
        $state = $transaction?->getStateMachineState()?->getTechnicalName()
            ?? $order->getTransactions()?->get($transaction?->getId() ?? '')?->getStateMachineState()?->getTechnicalName();
        if ($state !== null && $state !== 'open') {
            return;
        }

        try {
            $details = $this->giroCodeResolver->resolve(
                $order,
                $order->getSalesChannelId(),
                // Ohne geladene Währung zählt nur die Standardwährung als Euro; alles andere bekäme
                // sonst einen Code über einen Betrag in fremder Währung.
                $order->getCurrencyId() === Defaults::CURRENCY ? 'EUR' : '',
            );
            if ($details === null) {
                return;
            }

            $png = $this->qrCodeService->generatePng($details->payload, $details->size);
        } catch (Throwable $exception) {
            // Wie auf der Abschlussseite eine Beigabe: Die Bestellbestätigung geht auch ohne Code hinaus.
            $this->logger->error('RcQrCheckOut: GiroCode für die Bestellmail fehlgeschlagen', [
                'orderId' => $order->getId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $event->addTemplateData(self::TEMPLATE_VARIABLE, [
            'cid' => 'cid:' . self::CONTENT_ID,
            'recipient' => $details->recipient,
            'iban' => $details->iban,
            'amount' => $details->amount,
            'purpose' => $details->purpose,
        ]);
        $this->pendingPng = $png;
    }

    public function onBeforeSent(MailBeforeSentEvent $event): void
    {
        $png = $this->pendingPng;
        $this->pendingPng = null;

        if ($png === null) {
            return;
        }

        $message = $event->getMessage();
        $html = $message->getHtmlBody();
        if (!\is_string($html) || !str_contains($html, 'cid:' . self::CONTENT_ID)) {
            return;
        }

        $message->embed($png, self::CONTENT_ID, 'image/png');
    }

    public function reset(): void
    {
        $this->pendingPng = null;
    }

    private function isPaymentMail(string $templateId): bool
    {
        return \in_array($this->templateTypeLookup->technicalName($templateId), self::TEMPLATE_TYPES, true);
    }
}
