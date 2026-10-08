<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeResolver;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Hängt auf der Bestellbestätigungsseite einen GiroCode (SEPA-Überweisung als QR-Code) an die
 * Seite, damit der Kunde ihn mit seiner Banking-App scannt und die Überweisung vorausgefüllt anstößt.
 *
 * Ob es einen Code gibt, entscheidet `GiroCodeResolver`, dieselbe Stelle wie für die Bestellmail. Gibt es
 * keinen, fehlt er still, denn die Bestellung ist zu diesem Zeitpunkt schon abgeschlossen.
 */
class QrCodeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly GiroCodeResolver $giroCodeResolver,
        private readonly QrCodeService $qrCodeService,
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
        $order = $event->getPage()->getOrder();

        try {
            $details = $this->giroCodeResolver->resolve(
                $order,
                $event->getSalesChannelContext()->getSalesChannel()->getId(),
                $event->getSalesChannelContext()->getCurrency()->getIsoCode(),
            );
            if ($details === null) {
                return;
            }

            $svg = $this->qrCodeService->generateSvg($details->payload, $details->size);
        } catch (Throwable $exception) {
            // Der QR-Code ist eine Beigabe. Ein Fehler darf die schon abgeschlossene Bestellung nicht
            // mit einem 500 als gescheitert erscheinen lassen, deshalb landet er nur im Protokoll.
            $this->logger->error('RcQrCheckOut: GiroCode-Generierung fehlgeschlagen', [
                'orderId' => $order->getId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $event->getPage()->addExtension('rcQrCheckOut', new ArrayStruct([
            'enabled' => true,
            'svg' => $svg,
            'recipient' => $details->recipient,
            'iban' => $details->iban,
            'amount' => $details->amount,
        ]));
    }
}
