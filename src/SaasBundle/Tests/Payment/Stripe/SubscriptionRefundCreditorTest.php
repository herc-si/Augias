<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\SaasBundle\Tests\Payment\Stripe;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Test\Factory\PaymentMethodFactory;
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\SubscriptionInvoiceIssuer;
use Augias\SaasBundle\Payment\Stripe\SubscriptionRefundCreditor;
use Augias\Test\SaasKernel;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use function str_contains;

/**
 * A subscription payment Stripe gave back is credited on HERC SI's invoice:
 * a credit note, issued, sent, and recorded as refunded (Rémi, 23/11/2026).
 */
#[CoversClass(SubscriptionRefundCreditor::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class SubscriptionRefundCreditorTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private const int REFUNDED_AT = 1_788_400_000;

    private Company $billing;

    private Subscription $subscription;

    /**
     * @var array<string, mixed> the charge Stripe answers with
     */
    private array $charge = [];

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testAFullRefundCancelsTheInvoiceAndIsRecordedAsRefunded(): void
    {
        $invoice = $this->issuer()->issue('in_1');
        self::assertInstanceOf(Invoice::class, $invoice);

        $this->charge = $this->charge(1200);
        $creditNote = $this->creditor()->refunded('ch_1');

        self::assertInstanceOf(CreditNote::class, $creditNote);
        $this->em->clear();
        $creditNote = $this->em->find(CreditNote::class, $creditNote->getId());
        self::assertInstanceOf(CreditNote::class, $creditNote);

        self::assertTrue($creditNote->isNumbered());
        self::assertSame(CreditNoteStatus::Settled, $creditNote->getStatus(), 'Given back in full: nothing more is owed on it.');
        self::assertSame(CreditReason::Cancellation, $creditNote->getReason());
        self::assertSame('1200', (string) $creditNote->getTotal()->toBigInteger());
        self::assertSame($invoice->getId()?->toString(), $creditNote->getCreditedInvoice()?->getId()?->toString());
        self::assertTrue($creditNote->getCompany()->getId()->equals($this->billing->getId()));

        $allocations = $creditNote->getAllocations();
        self::assertCount(1, $allocations);
        $allocation = $allocations->first();
        self::assertInstanceOf(CreditNoteAllocation::class, $allocation);
        self::assertSame(AllocationKind::Refund, $allocation->getKind());
        self::assertSame('re_1', $allocation->getNotes());
        self::assertSame('2026-09-03', $allocation->getAllocatedOn()->format('Y-m-d'), 'On the day the money left.');

        $invoice = $this->em->find(Invoice::class, $invoice->getId());
        self::assertSame(InvoiceStatus::Paid, $invoice?->getStatus(), 'The invoice stays issued and paid: the credit note takes it back.');

        self::assertEmailCount(2);
    }

    public function testARefundReportedTwiceIsCreditedOnce(): void
    {
        $this->issuer()->issue('in_1');

        $this->charge = $this->charge(1200);
        self::assertNotNull($this->creditor()->refunded('ch_1'));
        self::assertNull($this->creditor()->refunded('ch_1'));

        self::assertCount(1, $this->em->getRepository(CreditNote::class)->findAll());
    }

    public function testAPartialRefundCreditsThatPartThenTheRest(): void
    {
        $this->issuer()->issue('in_1');

        $this->charge = $this->charge(500);
        $first = $this->creditor()->refunded('ch_1');
        self::assertInstanceOf(CreditNote::class, $first);
        self::assertSame('500', (string) $first->getTotal()->toBigInteger());
        self::assertSame(CreditReason::CommercialGesture, $first->getReason());
        self::assertStringContainsString('Solo', (string) $first->getLines()->first()?->getDescription());

        $this->charge = $this->charge(1200);
        $second = $this->creditor()->refunded('ch_1');
        self::assertInstanceOf(CreditNote::class, $second);
        self::assertSame('700', (string) $second->getTotal()->toBigInteger());
    }

    public function testARefundReportedBeforeThePaymentIsCreditedOnceTheInvoiceIsIssued(): void
    {
        $this->boot();

        $this->charge = $this->charge(1200);
        self::assertNull($this->creditor()->refunded('ch_1'), 'No invoice yet to credit.');

        $invoice = $this->issuer()->issue('in_1');
        self::assertInstanceOf(Invoice::class, $invoice);

        $creditNotes = $this->em->getRepository(CreditNote::class)->findAll();
        self::assertCount(1, $creditNotes);
        self::assertSame('1200', (string) $creditNotes[0]->getTotal()->toBigInteger());
    }

    public function testAChargeOfNoInvoiceIsLeftAlone(): void
    {
        $this->boot();

        $this->charge = ['invoice' => null] + $this->charge(1200);

        self::assertNull($this->creditor()->refunded('ch_1'));
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(int $refunded): array
    {
        return [
            'id' => 'ch_1',
            'object' => 'charge',
            'invoice' => 'in_1',
            'amount' => 1200,
            'amount_refunded' => $refunded,
            'refunded' => $refunded === 1200,
            'refunds' => ['data' => [['id' => 're_1', 'status' => 'succeeded', 'created' => self::REFUNDED_AT]]],
        ];
    }

    /**
     * Boots once per test, with Stripe answering from what the test set.
     */
    private function boot(): void
    {
        if (isset($this->billing)) {
            return;
        }

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->setupDoctrine();

        $factory = fn (string $method, string $url): JsonMockResponse => new JsonMockResponse(
            str_contains($url, '/charges/') ? $this->charge : $this->stripeInvoice(),
        );
        self::getContainer()->set(StripeApi::class, new StripeApi(new MockHttpClient($factory, 'https://api.stripe.com/v1/')));

        $billing = CompanyFactory::createOne(['name' => 'HERC SI']);
        $this->billing = $this->em->find(Company::class, $billing->getId()) ?? self::fail();
        PaymentMethodFactory::createOne(['company' => $this->billing, 'name' => 'Stripe', 'gatewayName' => 'stripe', 'factoryName' => 'offline']);

        $subscriber = CompanyFactory::createOne(['name' => 'Boulangerie Martin']);
        $subscriber = $this->em->find(Company::class, $subscriber->getId()) ?? self::fail();

        $plan = new Plan()->setName('Solo')->setPlanId('price_ref_solo')->setPrice(1200);
        $this->em->persist($plan);
        $this->subscription = new Subscription()
            ->setSubscriber($subscriber)
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setSubscriptionId('sub_ref')
            ->setStartDate(new DateTimeImmutable('2026-09-01'))
            ->setEndDate(new DateTimeImmutable('2026-10-01'));
        $this->em->persist($this->subscription);
        $this->em->flush();

        $_SERVER['AUGIAS_SAAS_BILLING_COMPANY'] = $_ENV['AUGIAS_SAAS_BILLING_COMPANY'] = $this->billing->getId()->toString();
    }

    private function issuer(): SubscriptionInvoiceIssuer
    {
        $this->boot();

        $issuer = self::getContainer()->get(SubscriptionInvoiceIssuer::class);
        self::assertInstanceOf(SubscriptionInvoiceIssuer::class, $issuer);

        return $issuer;
    }

    private function creditor(): SubscriptionRefundCreditor
    {
        $this->boot();

        $creditor = self::getContainer()->get(SubscriptionRefundCreditor::class);
        self::assertInstanceOf(SubscriptionRefundCreditor::class, $creditor);

        return $creditor;
    }

    /**
     * Stripe's invoice, its charge expanded as the issuer asks for it.
     *
     * @return array<string, mixed>
     */
    private function stripeInvoice(): array
    {
        return [
            'id' => 'in_1',
            'status' => 'paid',
            'amount_paid' => 1200,
            'currency' => 'eur',
            'billing_reason' => 'subscription_create',
            'subscription' => 'sub_ref',
            'subscription_details' => ['metadata' => ['subscription_id' => $this->subscription->getId()->toBase58()]],
            'customer_email' => 'contact@boulangerie.fr',
            'customer_name' => 'Claire Martin',
            'status_transitions' => ['paid_at' => 1_788_300_000],
            'lines' => ['data' => [['price' => ['id' => 'price_ref_solo'], 'period' => ['start' => 1_788_264_000, 'end' => 1_790_856_000]]]],
            'charge' => $this->charge !== [] ? $this->charge : null,
        ];
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_SAAS_BILLING_COMPANY'], $_ENV['AUGIAS_SAAS_BILLING_COMPANY']);
        parent::tearDown();
    }
}
