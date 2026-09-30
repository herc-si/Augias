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
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Test\Factory\PaymentMethodFactory;
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\SubscriptionInvoiceIssuer;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
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
use function array_fill;
use function strrpos;
use function substr;

/**
 * A subscription payment collected by Stripe becomes HERC SI's own invoice,
 * paid, in HERC SI's company and sequence, sent to the subscriber.
 */
#[CoversClass(SubscriptionInvoiceIssuer::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class SubscriptionInvoiceIssuerTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private Company $billing;

    private Subscription $subscription;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testAPaymentBecomesAPaidInvoiceOfTheBillingCompany(): void
    {
        $issuer = $this->issuer(1);

        $invoice = $issuer->issue('in_1');

        self::assertInstanceOf(Invoice::class, $invoice);
        $this->em->clear();
        $invoice = $this->em->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        self::assertTrue($invoice->getCompany()->getId()->equals($this->billing->getId()));
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame('1200', (string) $invoice->getTotal()->toBigInteger());
        self::assertSame('Boulangerie Martin', $invoice->getClient()?->getName());
        self::assertSame('123456789', $invoice->getClient()->getSiren());
        self::assertSame('contact@boulangerie.fr', $invoice->getClient()->getContacts()->first()->getEmail());
        self::assertStringContainsString('Solo', (string) $invoice->getLines()->first()->getDescription());
        self::assertStringContainsString('01/09/2026', (string) $invoice->getLines()->first()->getDescription());
        self::assertEmailCount(1);
    }

    public function testAPaymentDeliveredTwiceIsInvoicedOnce(): void
    {
        $issuer = $this->issuer(2);
        $first = $issuer->issue('in_1');
        $second = $issuer->issue('in_1');

        self::assertNotNull($first);
        self::assertSame($first->getId()?->toString(), $second?->getId()?->toString());
        self::assertCount(1, $this->em->getRepository(Payment::class)->findBy(['reference' => 'in_1']));
    }

    public function testNothingIsInvoicedWhenNothingWasPaid(): void
    {
        self::assertNull($this->issuer(1, ['amount_paid' => 0])->issue('in_1'));
    }

    public function testAClientAlreadyInHerCsiBooksIsReused(): void
    {
        $issuer = $this->issuer(2);
        $first = $issuer->issue('in_1');
        $second = $issuer->issue('in_2');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->getId()?->toString(), $second->getId()?->toString());
        self::assertSame($first->getClient()?->getId()?->toString(), $second->getClient()?->getId()?->toString());
    }

    private function seed(): void
    {
        $billing = CompanyFactory::createOne(['name' => 'HERC SI']);
        $this->billing = $this->em->find(Company::class, $billing->getId()) ?? self::fail();
        PaymentMethodFactory::createOne(['company' => $this->billing, 'name' => 'Stripe', 'gatewayName' => 'stripe', 'factoryName' => 'offline']);

        $subscriber = CompanyFactory::createOne(['name' => 'Boulangerie Martin']);
        $subscriber = $this->em->find(Company::class, $subscriber->getId()) ?? self::fail();
        TaxIdentifierFactory::createOne(['company' => $subscriber, 'client' => null, 'label' => 'SIREN', 'value' => '123456789']);

        $plan = new Plan()->setName('Solo')->setPlanId('price_inv_solo')->setPrice(1200);
        $this->em->persist($plan);
        $this->subscription = new Subscription()
            ->setSubscriber($subscriber)
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setSubscriptionId('sub_inv')
            ->setStartDate(new DateTimeImmutable('2026-09-01'))
            ->setEndDate(new DateTimeImmutable('2026-10-01'));
        $this->em->persist($this->subscription);
        $this->em->flush();
    }

    /**
     * Stripe answers the invoice asked for, as many times as it is asked.
     * The API is replaced before anything is created: creating a company
     * opens its subscription, which instantiates the payment integration.
     *
     * @param array<string, mixed> $overrides
     */
    private function issuer(int $calls, array $overrides = []): SubscriptionInvoiceIssuer
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->setupDoctrine();

        $factory = function (string $method, string $url) use ($overrides): JsonMockResponse {
            $id = substr($url, strrpos($url, '/') + 1);

            return new JsonMockResponse($this->stripeInvoice(['id' => $id] + $overrides));
        };
        self::getContainer()->set(StripeApi::class, new StripeApi(new MockHttpClient(array_fill(0, $calls, $factory), 'https://api.stripe.com/v1/')));

        $this->seed();
        $_SERVER['AUGIAS_SAAS_BILLING_COMPANY'] = $_ENV['AUGIAS_SAAS_BILLING_COMPANY'] = $this->billing->getId()->toString();

        $issuer = self::getContainer()->get(SubscriptionInvoiceIssuer::class);
        self::assertInstanceOf(SubscriptionInvoiceIssuer::class, $issuer);

        return $issuer;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function stripeInvoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'in_1',
            'status' => 'paid',
            'amount_paid' => 1200,
            'currency' => 'eur',
            'billing_reason' => 'subscription_cycle',
            'subscription' => 'sub_inv',
            'subscription_details' => ['metadata' => ['subscription_id' => $this->subscription->getId()->toBase58()]],
            'customer_email' => 'contact@boulangerie.fr',
            'customer_name' => 'Claire Martin',
            'customer_address' => ['line1' => '3 rue du Four', 'line2' => null, 'postal_code' => '69001', 'city' => 'Lyon', 'country' => 'FR'],
            'status_transitions' => ['paid_at' => 1_788_300_000],
            'lines' => ['data' => [['price' => ['id' => 'price_inv_solo'], 'period' => ['start' => 1_788_264_000, 'end' => 1_790_856_000]]]],
        ];
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_SAAS_BILLING_COMPANY'], $_ENV['AUGIAS_SAAS_BILLING_COMPANY']);
        parent::tearDown();
    }
}
