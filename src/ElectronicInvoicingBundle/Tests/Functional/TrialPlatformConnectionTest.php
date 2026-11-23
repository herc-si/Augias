<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Onboarding\PlatformConnectionGate;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * During a trial with no card on file, SUPER PDP is not connected: it costs
 * HERC SI the identity check and registers the company in the directory
 * (Rémi, 23/11/2026). A card on file — still in the trial — is enough.
 */
#[CoversClass(PlatformConnectionGate::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class TrialPlatformConnectionTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testATrialWithoutACardIsAskedForOneInsteadOfConnecting(): void
    {
        [$user, $setting] = $this->account(SubscriptionStatus::TRIAL, subscribed: false);

        $browser = $this->browser()->actingAs($user);
        $browser->visit('/electronic-invoicing/setup')
            ->assertSuccessful()
            ->assertSeeElement('#trial-needs-card')
            ->assertSee('Add a payment method')
            ->assertSee('December 23, 2026')
            ->assertNotSee('Connect my SUPER PDP account');

        $this->connectFromSettings($browser, $setting)->assertRedirectedTo('/electronic-invoicing/setup');
    }

    public function testATrialWithACardOnFileConnects(): void
    {
        [$user, $setting] = $this->account(SubscriptionStatus::TRIAL, subscribed: true);

        $browser = $this->browser()->actingAs($user);
        $browser->visit('/electronic-invoicing/setup')->assertSuccessful()->assertNotSeeElement('#trial-needs-card');

        $this->assertGoesToSuperPdp($this->connectFromSettings($browser, $setting));
    }

    public function testAPaidSubscriptionConnects(): void
    {
        [$user, $setting] = $this->account(SubscriptionStatus::ACTIVE, subscribed: true);

        $this->assertGoesToSuperPdp($this->connectFromSettings($this->browser()->actingAs($user), $setting));
    }

    private function connectFromSettings(KernelBrowser $browser, ElectronicInvoiceProviderSetting $setting): KernelBrowser
    {
        return $browser->interceptRedirects()->visit('/electronic-invoicing/super-pdp/connect/' . $setting->getId());
    }

    private function assertGoesToSuperPdp(KernelBrowser $browser): void
    {
        $location = (string) $browser->client()->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/electronic-invoicing/setup', $location);
        self::assertStringContainsString('app-id', $location, 'Off to SUPER PDP\'s consent page.');
    }

    /**
     * @return array{User, ElectronicInvoiceProviderSetting}
     */
    private function account(SubscriptionStatus $status, bool $subscribed): array
    {
        // This deployment's own SUPER PDP application: read at run time, so
        // it holds across the browser's kernel reboots.
        $_SERVER['AUGIAS_SUPER_PDP_CLIENT_ID'] = $_ENV['AUGIAS_SUPER_PDP_CLIENT_ID'] = 'app-id';
        $_SERVER['AUGIAS_SUPER_PDP_CLIENT_SECRET'] = $_ENV['AUGIAS_SUPER_PDP_CLIENT_SECRET'] = 'app-secret';
        // The hosted service, as `saas_enabled` reads it.
        $_SERVER['AUGIAS_PLATFORM'] = $_ENV['AUGIAS_PLATFORM'] = 'saas';

        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $user = UserFactory::createOne(['companies' => [], 'email' => 'jo@trial.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->addCompany($company, CompanyRole::Owner);
        $user->setTotpSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

        $plan = new Plan()->setName('Découverte')->setPlanId('price_trial')->setPrice(300);
        $this->em->persist($plan);

        // Creating the company already opened its subscription: that one is set.
        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $subscription
            ->setPlan($plan)
            ->setStatus($status)
            ->setSubscriptionId($subscribed ? 'sub_card' : null)
            ->setStartDate(new DateTimeImmutable('-7 days'))
            ->setEndDate(new DateTimeImmutable('2026-12-23 12:00'));
        $this->em->persist($subscription);

        $setting = new ElectronicInvoiceProviderSetting()->setName('SUPER PDP')->setProvider('super_pdp')->setSettings([]);
        $setting->setCompany($company);
        $this->em->persist($setting);
        $this->em->flush();

        return [$user, $setting];
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_SUPER_PDP_CLIENT_ID'], $_ENV['AUGIAS_SUPER_PDP_CLIENT_ID'], $_SERVER['AUGIAS_SUPER_PDP_CLIENT_SECRET'], $_ENV['AUGIAS_SUPER_PDP_CLIENT_SECRET'], $_SERVER['AUGIAS_PLATFORM'], $_ENV['AUGIAS_PLATFORM']);
        parent::tearDown();
    }
}
