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

namespace Augias\UserBundle\Tests\Functional;

use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Onboarding\Journey\SetupJourney;
use Augias\UserBundle\Test\Factory\UserFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * On the service the second factor is required: in the setup journey it
 * comes after who the company is, and before electronic invoicing (Rémi,
 * 23/11/2026) — never left for later.
 */
#[CoversClass(SetupJourney::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class SaasSetupJourneyTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheSecondFactorComesBeforeElectronicInvoicingAndCannotWait(): void
    {
        $user = UserFactory::createOne(['companies' => [], 'email' => 'jo@saas-journey.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->setTotpSecret('');
        $this->em->flush();

        $browser = $this->browser()->actingAs($user);
        $browser
            ->visit('/onboarding')
            ->fillField('form[company][companyName]', 'Acme')
            ->selectFieldOption('form[company][companyCurrency]', 'EUR')
            ->click('Continue')
            ->assertOn('/onboarding/identity')
            ->fillField('company_identity[siren]', '306138900')
            ->fillField('company_identity[siret]', '30613890001294')
            ->checkField('company_identity[vatExempt]')
            ->fillField('company_identity[street1]', '4 boulevard de Mons')
            ->fillField('company_identity[zip]', '59650')
            ->fillField('company_identity[city]', "Villeneuve-d'Ascq")
            ->click('Continue')
            ->assertOn('/onboarding/documents')
            ->click('Later')
            ->assertOn('/onboarding/security')
            ->assertNotSee('Later');

        // Nothing past it, inside the journey or out of it.
        $browser->visit('/onboarding/einvoicing')->assertOn('/onboarding/security');
        $browser->visit('/dashboard')->assertOn('/profile/2fa');

        // Set up meanwhile, as the second factor's page does: the journey
        // carries on to electronic invoicing.
        $this->em->getConnection()->executeStatement('UPDATE users SET totp_secret = ? WHERE email = ?', ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'jo@saas-journey.test']);

        $browser
            ->visit('/onboarding/security')
            ->click('Continue')
            ->assertOn('/onboarding/einvoicing')
            ->assertSee('Electronic invoicing');
    }
}
