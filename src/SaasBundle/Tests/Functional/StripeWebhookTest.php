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

namespace Augias\SaasBundle\Tests\Functional;

use const JSON_THROW_ON_ERROR;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\Test\SaasKernel;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\Ulid;
use function hash_hmac;
use function json_encode;
use function time;

/**
 * A payment Stripe reports reaches the subscription: the webhook route, its
 * signature check, the consumer and the sync, wired as the hosted service
 * runs them.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class StripeWebhookTest extends WebTestCase
{
    use DoctrineTestTrait;

    private const string SECRET = 'whsec_functional';

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testAPaidSubscriptionIsActivated(): void
    {
        $subscription = $this->trialSubscription();
        $client = $this->client([
            'id' => 'sub_func',
            'status' => 'active',
            'current_period_end' => 1_800_000_000,
            'metadata' => ['subscription_id' => $subscription->getId()->toBase58()],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_func_solo']]]],
        ]);

        $this->post($client, self::SECRET);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $subscription = $this->em->find(Subscription::class, $subscription->getId());
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame('sub_func', $subscription->getSubscriptionId());
        self::assertSame('price_func_solo', $subscription->getPlan()->getPlanId());
        self::assertEquals(new DateTimeImmutable('@1800000000'), $subscription->getEndDate());
    }

    public function testAnUnsignedRequestIsRefused(): void
    {
        $subscription = $this->trialSubscription();
        $client = $this->client([]);

        $this->post($client, 'whsec_someone_else');

        self::assertResponseStatusCodeSame(401);
        $this->em->clear();
        $subscription = $this->em->find(Subscription::class, $subscription->getId());
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame(SubscriptionStatus::TRIAL, $subscription->getStatus());
    }

    /**
     * @param array<string, mixed> $remote what Stripe answers for the subscription
     */
    private function client(array $remote): KernelBrowser
    {
        $_SERVER['AUGIAS_STRIPE_WEBHOOK_SECRET'] = $_ENV['AUGIAS_STRIPE_WEBHOOK_SECRET'] = self::SECRET;

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(StripeApi::class, new StripeApi(new MockHttpClient([new JsonMockResponse($remote)], 'https://api.stripe.com/v1/')));

        return $client;
    }

    private function post(KernelBrowser $client, string $secret): void
    {
        $body = json_encode(['id' => 'evt_func', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_func']]], JSON_THROW_ON_ERROR);
        $timestamp = time();

        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ], content: $body);
    }

    private function trialSubscription(): Subscription
    {
        $company = CompanyFactory::createOne(['name' => 'Stripe Shop']);

        $free = new Plan()->setName('Free')->setPlanId('free-' . new Ulid())->setPrice(0);
        $this->em->persist($free);
        $this->em->persist(new Plan()->setName('Solo')->setPlanId('price_func_solo')->setPrice(1200));

        $subscription = new Subscription()
            ->setSubscriber($this->em->getReference($company::class, $company->getId()))
            ->setPlan($free)
            ->setStatus(SubscriptionStatus::TRIAL)
            ->setStartDate(new DateTimeImmutable('2026-09-20'))
            ->setEndDate(new DateTimeImmutable('2026-10-04'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_STRIPE_WEBHOOK_SECRET'], $_ENV['AUGIAS_STRIPE_WEBHOOK_SECRET']);
        parent::tearDown();
    }
}
