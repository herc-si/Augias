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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\OperatorAccess;
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Entity\SupportSettings;
use Augias\CoreBundle\Enum\AccessReason;
use Augias\CoreBundle\Enum\SupportRequestStatus;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SaasBundle\Support\SupportDesk;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * A company asking the people who run the service for help, and someone
 * coming in on that request: under their own name, read-only, for as long as
 * the company said, and on the record.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class SupportAccessTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private Company $shop;

    private Client $client;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testNothingIsOfferedUntilTheOperatorTurnsItOn(): void
    {
        $owner = $this->member(CompanyRole::Owner);

        $this->as($owner)
            ->visit('/support/')
            ->assertStatus(404)
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertNotSee('Ask for help');
    }

    public function testTheOwnerAsksAndTheOperatorIsTold(): void
    {
        $this->settings(notify: 'support@example.test', provider: 'Acme Hosting');
        $owner = $this->member(CompanyRole::Owner);

        $this->as($owner)
            ->visit('/support/')
            ->assertSuccessful()
            ->assertSee('Ask Acme Hosting for help')
            ->fillField('support_request[message]', 'The VAT return will not close.')
            ->selectFieldOption('support_request[hours]', '3 days')
            ->click('Send the request')
            ->assertOn('/support/')
            ->assertSee('Your request is waiting for Acme Hosting to take it.')
            ->assertSee('A request for help to Acme Hosting is open');

        $request = $this->onlyRequest();
        self::assertSame(72, $request->getHours());
        self::assertSame((string) $owner->getEmail(), $request->getRequestedBy());
    }

    public function testTheOperatorIsToldOfANewRequest(): void
    {
        $this->settings(notify: 'support@example.test');
        $owner = $this->member(CompanyRole::Owner);

        $this->request($owner);

        $email = $this->lastEmail();
        self::assertSame('support@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('Request for help — Shop', $email->getSubject());
        self::assertStringContainsString('The VAT return will not close.', (string) $email->getTextBody());
    }

    public function testABillingMemberCannotLetAnyoneIn(): void
    {
        $this->settings();
        $billing = $this->member(CompanyRole::Billing);

        $this->as($billing)
            ->visit('/support/')
            ->assertStatus(403);
    }

    public function testTheOperatorComesInReadOnlyAndEverythingIsRecorded(): void
    {
        $this->settings(provider: 'Acme Hosting');
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);

        $this->desk()->accept($request, (string) $operator->getEmail());

        // The company hears it at once.
        $email = $this->lastEmail();
        self::assertSame((string) $owner->getEmail(), $email->getTo()[0]->getAddress());

        $this->as($operator)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/dashboard')
            ->assertSee('Support session at Shop')
            ->visit('/clients/')
            ->assertSuccessful()
            ->visit('/clients/view/' . $this->client->getId())
            ->assertSuccessful()
            ->assertSee('Acme')
            // Nothing changes, nothing leaves, nobody else is let in.
            ->visit('/invoices/create/' . $this->client->getId())
            ->assertStatus(403)
            ->assertSee('read-only')
            ->visit('/settings')
            ->assertStatus(403)
            ->visit('/profile/exports')
            ->assertStatus(403)
            ->visit('/support/')
            ->assertStatus(403)
            ->visit('/billing/')
            ->assertStatus(403);

        $this->em->clear();
        $log = $this->em->getRepository(OperatorAccess::class)->findBy(['operator' => (string) $operator->getEmail()]);
        self::assertNotEmpty($log);

        $details = array_map(static fn (OperatorAccess $row): ?string => $row->getDetail(), $log);
        self::assertContains('GET /clients/view/' . $this->client->getId(), $details);
        self::assertContains('GET /settings', $details, 'A refused attempt is on the record too.');

        foreach ($log as $row) {
            self::assertSame(AccessReason::SupportSession, $row->getReasonKind());
            self::assertTrue($row->getCompany()->getId()->equals($this->shop->getId()));
        }

        // Members see the door is open, and to whom.
        $this->as($owner)
            ->visit('/dashboard')
            ->assertSee((string) $operator->getEmail() . ' (Acme Hosting) may look at the company, read-only');
    }

    /**
     * Arriving from the operator's tooling, not yet signed in to the
     * application: the sign-in leads on to the company, not to setting one up
     * — the visitor belongs to none.
     */
    public function testSigningInOnTheWayLeadsIntoTheCompany(): void
    {
        $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $operator->setPassword($hasher->hashPassword($operator, 'password'));
        $this->em->flush();

        $this->browser()
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/login')
            ->fillField('_username', (string) $operator->getEmail())
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->assertOn('/dashboard')
            ->assertSee('Support session at Shop');
    }

    public function testOnlyWhoeverTookTheRequestComesIn(): void
    {
        $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $someoneElse = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $this->as($someoneElse)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/support/ended')
            ->visit('/clients/')
            ->assertNotSee('Acme');
    }

    public function testAPendingRequestLetsNobodyIn(): void
    {
        $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);

        $this->as($operator)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/support/ended');
    }

    public function testRevokingEndsTheVisitUnderWay(): void
    {
        $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $visitor = $this->as($operator)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/dashboard');

        $browser = $this->as($owner)->visit('/support/')->assertSuccessful();
        $token = $browser->crawler()->filter('form[action="/support/' . $request->getId() . '/revoke"] input[name=_token]')->attr('value');
        $browser->post('/support/' . $request->getId() . '/revoke', ['body' => ['_token' => $token]])
            ->assertOn('/support/')
            ->assertSee('Access has been closed.');

        $visitor->visit('/clients/')
            ->assertOn('/support/ended')
            ->assertSee('Support session ended');
    }

    public function testTheDoorClosesByItselfWhenTimeRunsOut(): void
    {
        $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $visitor = $this->as($operator)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/dashboard');

        // Run out, as if the hours had passed: nothing has to write it down.
        $this->em->getConnection()->update(
            SupportRequest::TABLE_NAME,
            ['expires_at' => '2000-01-01 00:00:00'],
            ['id' => $request->getId()],
            ['id' => UlidType::NAME],
        );

        $visitor->visit('/clients/')->assertOn('/support/ended');
    }

    public function testSwitchingTheFeatureOffClosesEveryDoor(): void
    {
        $settings = $this->settings();
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $visitor = $this->as($operator)
            ->visit('/support/' . $request->getId() . '/enter')
            ->assertOn('/dashboard');

        $settings = $this->em->find(SupportSettings::class, $settings->getId());
        self::assertInstanceOf(SupportSettings::class, $settings);
        $settings->setEnabled(false);
        $this->em->flush();

        $visitor->visit('/clients/')->assertOn('/support/ended');
    }

    public function testClosingARequestTellsTheCompanyWhatWasDone(): void
    {
        $this->settings(provider: 'Acme Hosting');
        $owner = $this->member(CompanyRole::Owner);
        $operator = $this->outsider();
        $request = $this->request($owner);
        $this->desk()->accept($request, (string) $operator->getEmail());

        $this->desk()->resolve($request, (string) $operator->getEmail(), 'Reopened the March period; the return now closes.');

        $email = $this->lastEmail();
        self::assertStringContainsString('Reopened the March period', (string) $email->getTextBody());

        $this->as($owner)
            ->visit('/support/')
            ->assertSee('Reopened the March period; the return now closes.')
            ->assertSee('Closed')
            ->assertNotSee('may look at the company');

        self::assertSame(SupportRequestStatus::Resolved, $this->onlyRequest()->getStatus());
    }

    private function as(User $user): KernelBrowser
    {
        return $this->browser()->actingAs($user);
    }

    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function desk(): SupportDesk
    {
        // Only the SaaS kernel has it; PHPStan reads the self-hosted container.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        return self::getContainer()->get(SupportDesk::class);
    }

    private function settings(?string $notify = null, ?string $provider = null): SupportSettings
    {
        $settings = new SupportSettings()
            ->setEnabled(true)
            ->setProviderName($provider)
            ->setNotifyEmail($notify);
        $this->desk()->saveSettings($settings);

        return $settings;
    }

    private function request(User $owner): SupportRequest
    {
        $shop = $this->em->find(Company::class, $this->shop->getId());
        self::assertInstanceOf(Company::class, $shop);

        return $this->desk()->open($shop, (string) $owner->getEmail(), 'The VAT return will not close.', 24);
    }

    private function onlyRequest(): SupportRequest
    {
        $this->em->clear();
        $filters = $this->em->getFilters();

        if ($filters->isEnabled('company')) {
            $filters->disable('company');
        }

        $requests = $this->em->getRepository(SupportRequest::class)->findAll();
        self::assertCount(1, $requests);

        return $requests[0];
    }

    private function outsider(): User
    {
        $user = UserFactory::createOne(['companies' => []]);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function member(CompanyRole $role): User
    {
        if (! isset($this->shop)) {
            $shop = CompanyFactory::createOne(['name' => 'Shop']);
            self::assertInstanceOf(Company::class, $shop);
            $this->shop = $shop;
            $client = ClientFactory::createOne(['company' => $this->shop, 'name' => 'Acme']);
            self::assertInstanceOf(Client::class, $client);
            $this->client = $client;
        }

        $user = $this->outsider();
        $shop = $this->em->find(Company::class, $this->shop->getId());
        self::assertInstanceOf(Company::class, $shop);
        $user->addCompany($shop, $role);
        $this->em->flush();

        return $user;
    }
}
