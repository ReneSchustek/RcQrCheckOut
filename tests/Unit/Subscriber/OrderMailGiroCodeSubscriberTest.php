<?php

declare(strict_types=1);

namespace Ruhrcoder\RcQrCheckOut\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeBuilder;
use Ruhrcoder\RcQrCheckOut\Service\GiroCodeResolver;
use Ruhrcoder\RcQrCheckOut\Service\MailTemplateTypeLookup;
use Ruhrcoder\RcQrCheckOut\Service\QrCodeService;
use Ruhrcoder\RcQrCheckOut\Subscriber\OrderMailGiroCodeSubscriber;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeSentEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\Mime\Email;

/**
 * Der GiroCode in der Bestellbestätigung: Variable für die Vorlage beim Prüfen, Bild mit Content-ID
 * beim Versand. Ginge das schief, stünde in der Mail ein leerer Kasten, ein loser Anhang oder ein Code
 * zu einer schon bezahlten Bestellung.
 */
final class OrderMailGiroCodeSubscriberTest extends TestCase
{
    private const TEMPLATE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PM_ALLOWED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SALES_CHANNEL = 'cccccccccccccccccccccccccccccccc';

    public function testAnOpenPrepaymentOrderGetsTheCodeInTheMail(): void
    {
        $subscriber = $this->subscriber();
        $validate = $this->validateEvent($this->order());

        $subscriber->onBeforeValidate($validate);

        $variable = $validate->getTemplateData()[OrderMailGiroCodeSubscriber::TEMPLATE_VARIABLE] ?? null;
        self::assertIsArray($variable);
        self::assertSame('cid:girocode', $variable['cid']);
        self::assertSame('DE89370400440532013000', $variable['iban']);
        self::assertSame('Bestellung 10234', $variable['purpose'], 'Der Verwendungszweck ist die Zeile aus dem Datensatz.');

        $mail = (new Email())->html('<img src="cid:girocode">')->text('GiroCode');
        $subscriber->onBeforeSent(new MailBeforeSentEvent([], $mail, new Context(new SystemSource())));

        $inline = array_filter($mail->getAttachments(), static fn ($part): bool => $part->getDisposition() === 'inline');
        self::assertCount(1, $inline, 'Das Bild muss eingebettet sein, nicht angehängt.');
        self::assertStringStartsWith("\x89PNG", array_values($inline)[0]->getBody());
    }

    /**
     * Eine Vorlage ohne Platzhalter bekommt kein loses Bild.
     */
    public function testATemplateWithoutThePlaceholderGetsNoImage(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->onBeforeValidate($this->validateEvent($this->order()));

        $mail = (new Email())->html('<p>Danke</p>')->text('Danke');
        $subscriber->onBeforeSent(new MailBeforeSentEvent([], $mail, new Context(new SystemSource())));

        self::assertSame([], $mail->getAttachments());
    }

    /**
     * „Bestellung erneut senden" nach dem Zahlungseingang: kein Überweisungscode mehr.
     */
    public function testAPaidOrderGetsNoCode(): void
    {
        $validate = $this->validateEvent($this->order('paid'));
        $this->subscriber()->onBeforeValidate($validate);

        self::assertArrayNotHasKey(OrderMailGiroCodeSubscriber::TEMPLATE_VARIABLE, $validate->getTemplateData());
    }

    /**
     * So lädt der Flow die Bestellung (`OrderProvider`): die primäre Transaktion ohne Zustand, den
     * Zustand nur in der Liste der Transaktionen.
     */
    public function testThePaidStateIsFoundInTheTransactionList(): void
    {
        $order = $this->order('paid');
        $listed = $order->getPrimaryOrderTransaction();
        self::assertNotNull($listed);

        $bare = new OrderTransactionEntity();
        $bare->setId($listed->getId());
        $bare->setPaymentMethodId(self::PM_ALLOWED);
        $order->setPrimaryOrderTransaction($bare);
        $order->setTransactions(new OrderTransactionCollection([$listed]));

        $validate = $this->validateEvent($order);
        $this->subscriber()->onBeforeValidate($validate);

        self::assertArrayNotHasKey(OrderMailGiroCodeSubscriber::TEMPLATE_VARIABLE, $validate->getTemplateData());
    }

    public function testOtherMailsGetNoCode(): void
    {
        $validate = $this->validateEvent($this->order());
        $this->subscriber('order_delivery.state.shipped')->onBeforeValidate($validate);

        self::assertArrayNotHasKey(OrderMailGiroCodeSubscriber::TEMPLATE_VARIABLE, $validate->getTemplateData());
    }

    /**
     * Ein Bild, das aus einer gescheiterten Mail übrig ist, darf nicht in die nächste geraten.
     */
    public function testALeftoverImageDoesNotReachTheNextMail(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->onBeforeValidate($this->validateEvent($this->order()));
        $subscriber->reset();

        $mail = (new Email())->html('<img src="cid:girocode">')->text('GiroCode');
        $subscriber->onBeforeSent(new MailBeforeSentEvent([], $mail, new Context(new SystemSource())));

        self::assertSame([], $mail->getAttachments());
    }

    private function subscriber(string $templateType = 'order_confirmation_mail'): OrderMailGiroCodeSubscriber
    {
        $config = new StaticSystemConfigService([
            self::SALES_CHANNEL => [
                'RcQrCheckOut.config.qrCodeEnabled' => true,
                'RcQrCheckOut.config.paymentMethodIds' => [self::PM_ALLOWED],
                'RcQrCheckOut.config.recipient' => 'Musterhandel Nord GmbH',
                'RcQrCheckOut.config.iban' => 'DE89370400440532013000',
                'RcQrCheckOut.config.bic' => '',
                'RcQrCheckOut.config.purposeTemplate' => 'Bestellung {orderNumber}',
            ],
        ]);

        $lookup = $this->createMock(MailTemplateTypeLookup::class);
        $lookup->method('technicalName')->willReturn($templateType);

        return new OrderMailGiroCodeSubscriber(
            new GiroCodeResolver($config, new GiroCodeBuilder()),
            new QrCodeService(),
            $lookup,
            new NullLogger(),
        );
    }

    private function order(string $state = 'open'): OrderEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('dddddddddddddddddddddddddddddddd');
        $transaction->setPaymentMethodId(self::PM_ALLOWED);
        $transaction->setStateMachineState($stateEntity);

        $order = new OrderEntity();
        $order->setId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $order->setOrderNumber('10234');
        $order->setAmountTotal(18.91);
        $order->setCurrencyId(Defaults::CURRENCY);
        $order->setSalesChannelId(self::SALES_CHANNEL);
        $order->setPrimaryOrderTransaction($transaction);

        return $order;
    }

    private function validateEvent(OrderEntity $order): MailBeforeValidateEvent
    {
        return new MailBeforeValidateEvent(['templateId' => self::TEMPLATE_ID], new Context(new SystemSource()), ['order' => $order]);
    }
}
