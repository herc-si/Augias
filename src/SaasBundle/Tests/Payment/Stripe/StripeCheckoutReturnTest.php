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
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeCheckoutReturn;
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
 * Back from Checkout, a trial just subscribed is billed at once, without
 * waiting for the webhook.
 */
#[CoversClass(StripeCheckoutReturn::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class StripeCheckoutReturnTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private Subscription $subscription;

    /**
     * @var array<string, mixed>
     */
    private array $session = [];

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheCompanysOwnCheckoutBillsItsTrialAtOnce(): void
    {
        $return = $this->checkoutReturn();
        $this->session = ['id' => 'cs_1', 'client_reference_id' => $this->subscription->getId()->toBase58(), 'subscription' => 'sub_new'];

        $return->settle('cs_1', $this->subscription);

        $this->em->clear();
        $subscription = $this->em->find(Subscription::class, $this->subscription->getId());
        self::assertSame('sub_new', $subscription?->getSubscriptionId());
        self::assertTrue($subscription->isExternallyBilled());
        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
    }

    public function testAnotherCompanysCheckoutIsIgnored(): void
    {
        $return = $this->checkoutReturn();
        $this->session = ['id' => 'cs_1', 'client_reference_id' => 'someone-else', 'subscription' => 'sub_new'];

        $return->settle('cs_1', $this->subscription);

        $this->em->clear();
        self::assertNull($this->em->find(Subscription::class, $this->subscription->getId())?->getSubscriptionId());
    }

    private function checkoutReturn(): StripeCheckoutReturn
    {
        // The API is replaced before anything is created: creating a company
        // opens its subscription, which instantiates the payment integration.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->setupDoctrine();

        $factory = fn (string $method, string $url): JsonMockResponse => new JsonMockResponse(str_contains($url, '/checkout/sessions/') ? $this->session : [
            'id' => 'sub_new',
            'status' => 'trialing',
            'customer' => 'cus_1',
            'cancel_at_period_end' => false,
            'current_period_start' => 1_795_000_000,
            'current_period_end' => 1_797_000_000,
            'trial_end' => 1_797_000_000,
            'metadata' => ['subscription_id' => $this->subscription->getId()->toBase58()],
            'items' => ['data' => [['price' => ['id' => 'price_ret']]]],
        ]);
        self::getContainer()->set(StripeApi::class, new StripeApi(new MockHttpClient($factory, 'https://api.stripe.com/v1/')));

        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId()) ?? self::fail();

        $plan = new Plan()->setName('Découverte')->setPlanId('price_ret')->setPrice(300);
        $this->em->persist($plan);
        $this->subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $this->subscription
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::TRIAL)
            ->setSubscriptionId(null)
            ->setStartDate(new DateTimeImmutable('-7 days'))
            ->setEndDate(new DateTimeImmutable('+23 days'));
        $this->em->persist($this->subscription);
        $this->em->flush();

        $return = self::getContainer()->get(StripeCheckoutReturn::class);
        self::assertInstanceOf(StripeCheckoutReturn::class, $return);

        return $return;
    }
}
