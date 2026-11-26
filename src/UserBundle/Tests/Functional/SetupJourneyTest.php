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

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Onboarding\Journey\SetupJourney;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;
use function str_replace;

/**
 * A new account is taken from its company to its first invoice, the client
 * last (Rémi, 23/11/2026): who the company is, its documents, the second
 * factor, electronic invoicing, then a first client.
 */
#[CoversClass(SetupJourney::class)]
#[Group('functional')]
final class SetupJourneyTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;
    use EnsureApplicationInstalled;

    public function testANewAccountGoesFromItsCompanyToItsFirstInvoice(): void
    {
        $browser = $this->browser()->actingAs($this->createUser('jo@journey.test'));

        $this->startWithCompany($browser)
            ->assertOn('/onboarding/identity')
            ->assertSee('Step 2 of 6')
            ->assertSee('Your company');

        $this->saveIdentity($browser)
            ->assertOn('/onboarding/documents')
            ->fillField('form[bankName]', 'Banque Populaire')
            ->fillField('form[iban]', 'FR76 3000 6000 0112 3456 7890 189')
            ->click('Continue')
            ->assertOn('/onboarding/security')
            ->assertSee('Protect your account')
            ->click('Later')
            ->assertOn('/onboarding/einvoicing')
            ->click('Later')
            ->assertOn('/onboarding/first_invoice')
            ->fillField('form[client][clientName]', 'John Doe')
            ->fillField('form[client][clientEmail]', 'john@example.com')
            ->fillField('form[invoice][invoiceDescription]', 'Website Design')
            ->fillField('form[invoice][invoiceAmount]', '1500.00')
            ->click('Save and finish')
            ->assertSee('Your first invoice is ready!');

        $this->em->clear();
        $invoices = $this->em->getRepository(Invoice::class)->findAll();
        self::assertCount(1, $invoices);
        self::assertSame('Website Design', $invoices[0]->getLines()->first()?->getDescription());

        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);
        self::assertSame('FR7630006000011234567890189', str_replace(' ', '', (string) $config->get(CompanyBankDetails::IBAN, $invoices[0]->getCompany())));

        $browser->visit('/onboarding')->assertOn('/dashboard');
    }

    public function testWhoTheCompanyIsCannotBeLeftForLater(): void
    {
        $browser = $this->browser()->actingAs($this->createUser('jo@journey.test'));

        $this->startWithCompany($browser)->assertOn('/onboarding/identity')->assertNotSee('Later');

        // Nor jumped past.
        $browser->visit('/onboarding/first_invoice')->assertOn('/onboarding/identity');
    }

    public function testFinishingWithoutAClientLeavesNoClient(): void
    {
        $browser = $this->browser()->actingAs($this->createUser('jo@journey.test'));

        $this->saveIdentity($this->startWithCompany($browser))
            ->click('Later')
            ->click('Later')
            ->click('Later')
            ->assertOn('/onboarding/first_invoice')
            ->click('Finish without a client')
            ->assertOn('/dashboard');

        self::assertSame(0, $this->em->getRepository(Client::class)->count([]));
    }

    /**
     * The amount starts at 0: a client alone, with the invoice left blank,
     * is saved without an invoice and without an error on the amount.
     */
    public function testAClientAloneIsSavedWithoutAnInvoice(): void
    {
        $browser = $this->browser()->actingAs($this->createUser('jo@journey.test'));

        $this->saveIdentity($this->startWithCompany($browser))
            ->click('Later')
            ->click('Later')
            ->click('Later')
            ->fillField('form[client][clientName]', 'John Doe')
            ->fillField('form[client][clientEmail]', 'john@example.com')
            ->fillField('form[invoice][invoiceAmount]', '0')
            ->click('Save and finish')
            ->assertOn('/dashboard')
            ->assertSee('What you left for later is waiting');

        self::assertSame(1, $this->em->getRepository(Client::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }

    public function testSigningInAgainPicksTheJourneyUpWhereItStopped(): void
    {
        $user = $this->createUser('jo@journey.test');
        $this->startWithCompany($this->browser()->actingAs($user));

        $this->browser()
            ->visit('/login')
            ->fillField('_username', 'jo@journey.test')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->assertOn('/onboarding/identity');
    }

    public function testAnInvitedAccountSkipsTheJourney(): void
    {
        $company = CompanyFactory::createOne();
        $user = UserFactory::createOne(['email' => 'invited@journey.test', 'companies' => [$company]]);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $this->em->flush();

        $this->browser()
            ->visit('/login')
            ->fillField('_username', 'invited@journey.test')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->assertOn('/dashboard');
    }

    private function startWithCompany(KernelBrowser $browser): KernelBrowser
    {
        return $browser
            ->visit('/onboarding')
            ->assertSee('Step 1 of 6')
            ->fillField('form[company][companyName]', 'Acme')
            ->selectFieldOption('form[company][companyCurrency]', 'EUR')
            ->click('Continue');
    }

    private function saveIdentity(KernelBrowser $browser): KernelBrowser
    {
        return $browser
            ->assertOn('/onboarding/identity')
            ->fillField('company_identity[siren]', '306138900')
            ->fillField('company_identity[siret]', '30613890001294')
            ->checkField('company_identity[vatExempt]')
            ->fillField('company_identity[street1]', '4 boulevard de Mons')
            ->fillField('company_identity[zip]', '59650')
            ->fillField('company_identity[city]', "Villeneuve-d'Ascq")
            ->click('Continue');
    }

    private function createUser(string $email, #[SensitiveParameter] string $password = 'password'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setVerified(true);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
