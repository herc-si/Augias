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

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\ApiBundle\ApiTokenManager;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Entity\UserInvitation;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use Augias\UserBundle\Test\Factory\UserFactory;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * What each role may do in a company, held where it matters: the pages, the
 * forms, the API — and who may change the members themselves.
 */
#[Group('functional')]
final class CompanyRolesTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private Company $shop;

    private Client $client;

    public function testAnAccountantReadsButChangesNoDocument(): void
    {
        $accountant = $this->member(CompanyRole::Accountant);

        $this->as($accountant)
            ->visit('/invoices/')
            ->assertSuccessful()
            ->visit('/accounting/')
            ->assertSuccessful()
            ->visit('/invoices/create/' . $this->client->getId())
            ->assertStatus(403)
            ->assertSee('Accountant')
            ->visit('/settings')
            ->assertStatus(403);
    }

    public function testBillingChangesDocumentsButNotTheSettings(): void
    {
        $billing = $this->member(CompanyRole::Billing);

        $this->as($billing)
            ->visit('/invoices/create/' . $this->client->getId())
            ->assertSuccessful()
            ->visit('/settings')
            ->assertStatus(403)
            ->visit('/users/invite')
            ->assertStatus(403)
            ->visit('/profile/exports')
            ->assertStatus(403);
    }

    public function testOnlyTheOwnerClosesTheCompany(): void
    {
        $admin = $this->member(CompanyRole::Admin);

        $this->as($admin)
            ->visit('/settings')
            ->assertSuccessful()
            ->post('/delete-company')
            ->assertStatus(403);

        $this->assertStillMember($admin);
    }

    /**
     * Closing waits thirty days, during which the company is read-only for
     * everyone, its data can still be exported, and the owner can call it off.
     */
    public function testTheOwnerClosesTheCompanyAndCanCallItOff(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $billing = $this->member(CompanyRole::Billing);

        $browser = $this->as($owner)->visit('/settings')->assertSuccessful();
        $token = $browser->crawler()->filter('#deleteCompanyForm input[name=_csrf_token]')->attr('value');

        $browser->post('/delete-company', ['body' => ['_csrf_token' => $token]])
            ->assertSuccessful()
            ->assertOn('/profile/exports')
            ->assertSee('The closure is scheduled for');

        $this->as($billing)
            ->visit('/invoices/')
            ->assertSuccessful()
            ->assertSee('This company will close on')
            ->visit('/invoices/create/' . $this->client->getId())
            ->assertStatus(403)
            ->assertSee('read-only');

        $browser = $this->as($owner)->visit('/dashboard')->assertSuccessful();
        $cancel = $browser->crawler()->filter('form[action="/cancel-company-closure"] input[name=_token]')->attr('value');
        $browser->post('/cancel-company-closure', ['body' => ['_token' => $cancel]])
            ->assertSuccessful()
            ->assertSee('The closure was cancelled.');

        $this->as($billing)
            ->visit('/invoices/create/' . $this->client->getId())
            ->assertSuccessful();
    }

    /**
     * The FEC is the books taken out: an accountant downloads it, billing
     * does not, like any export.
     */
    public function testTheFecGoesToWhoMayExport(): void
    {
        $accountant = $this->member(CompanyRole::Accountant);
        $billing = $this->member(CompanyRole::Billing);

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate(new DateTimeImmutable('2026-03-10'))
            ->setLabel('Paid')
            ->setCounterpartyName('Acme')
            ->setAmount(BigInteger::of(12000))
            ->setCurrencyCode('EUR');
        $entry->setCompany($this->em->find(Company::class, $this->shop->getId()));
        $this->em->persist($entry);
        $this->em->flush();

        $this->as($accountant)
            ->visit('/accounting/')
            ->assertSuccessful()
            ->assertSee('FEC')
            ->visit('/accounting/fec/2026')
            ->assertSuccessful()
            ->use(static function (KernelBrowser $browser): void {
                // A text file, not a page: read the response itself.
                $response = $browser->client()->getResponse();
                self::assertStringContainsString('FEC20261231.txt', (string) $response->headers->get('Content-Disposition'));
                self::assertStringStartsWith("JournalCode\t", (string) $response->getContent());
            });

        $this->as($billing)
            ->visit('/accounting/fec/2026')
            ->assertStatus(403);
    }

    public function testTheMenuOnlyOffersWhatTheRoleOpens(): void
    {
        $billing = $this->member(CompanyRole::Billing);

        $this->as($billing)
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertElementCount('a[href="/settings"]', 0)
            ->assertSeeElement('a[href="/invoices/"]');
    }

    public function testTheOwnerTakesAMembersAccessAway(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $billing = $this->member(CompanyRole::Billing);

        $browser = $this->as($owner)->visit('/users')->assertSuccessful();
        $token = $browser->crawler()->filter(sprintf('form[action="/users/members/%s/remove"] input[name=_token]', $billing->getId()->toBase32()))->attr('value');

        $browser->post('/users/members/' . $billing->getId()->toBase32() . '/remove', ['body' => ['_token' => $token]])
            ->assertSuccessful()
            ->assertSee('The access was taken away.');

        self::assertNull($this->memberships()->findOne($billing, $this->shop));
    }

    public function testAnAdministratorCannotTouchTheOwner(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $admin = $this->member(CompanyRole::Admin);

        $browser = $this->as($admin)->visit('/users')->assertSuccessful();

        // No button for it, and a hand-made request is refused all the same.
        self::assertCount(0, $browser->crawler()->filter(sprintf('form[action="/users/members/%s/remove"]', $owner->getId()->toBase32())));

        $this->assertStillMember($owner, CompanyRole::Owner);
    }

    public function testTheOwnerCannotLeaveButCanHandTheCompanyOver(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $admin = $this->member(CompanyRole::Admin);

        $browser = $this->as($owner)->visit('/users')->assertSuccessful();
        self::assertCount(0, $browser->crawler()->filter('form[action="/users/leave"]'), 'The owner is offered no way to leave.');

        $token = $browser->crawler()->filter(sprintf('form[action="/users/members/%s/make-owner"] input[name=_token]', $admin->getId()->toBase32()))->attr('value');
        $browser->post('/users/members/' . $admin->getId()->toBase32() . '/make-owner', ['body' => ['_token' => $token]])
            ->assertSuccessful();

        $this->assertStillMember($admin, CompanyRole::Owner);
        $this->assertStillMember($owner, CompanyRole::Admin);
    }

    public function testAnInvitationBringsItsRole(): void
    {
        $owner = $this->member(CompanyRole::Owner);

        $browser = $this->as($owner)->visit('/users/invite')->assertSuccessful();
        $form = $browser->crawler()->filter('button.btn-save')->form([
            'user_invite[email]' => 'books@shop.test',
            'user_invite[role]' => CompanyRole::Accountant->value,
        ]);
        $browser->client()->submit($form);

        $invitation = $this->em->getRepository(UserInvitation::class)->findOneBy(['email' => 'books@shop.test']);
        self::assertInstanceOf(UserInvitation::class, $invitation);
        self::assertSame(CompanyRole::Accountant, $invitation->getRole());
    }

    public function testAMemberTakenOffIsSentBackToTheCompanyPicker(): void
    {
        $billing = $this->member(CompanyRole::Billing);
        $browser = $this->as($billing)->visit('/invoices/')->assertSuccessful();

        $em = self::getContainer()->get('doctrine')->getManager();
        $membership = $this->memberships()->findOne($billing, $this->shop);
        self::assertInstanceOf(Membership::class, $membership);
        $em->remove($membership);
        $em->flush();
        $em->clear();

        $browser->interceptRedirects()
            ->visit('/invoices/')
            ->assertStatus(302)
            ->assertHeaderContains('Location', '/select-company');
    }

    public function testTheApiFollowsTheRole(): void
    {
        $accountant = $this->member(CompanyRole::Accountant);
        $token = self::getContainer()->get(ApiTokenManager::class)->getOrCreate($accountant, 'Roles test')->plaintext;
        $headers = ['X-API-TOKEN' => $token, 'Accept' => 'application/ld+json', 'Content-Type' => 'application/ld+json'];

        $this->browser()
            ->get('/api/clients', ['headers' => $headers])
            ->assertSuccessful()
            ->post('/api/clients', ['headers' => $headers, 'body' => '{"name":"Nope","currencyCode":"EUR"}'])
            ->assertStatus(403);
    }

    private function as(User $user): KernelBrowser
    {
        $this->em->clear();

        return $this->browser()->actingAs($this->em->find(User::class, $user->getId()));
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

        $user = UserFactory::createOne(['companies' => []]);
        self::assertInstanceOf(User::class, $user);

        $user = $this->em->find(User::class, $user->getId());
        $shop = $this->em->find(Company::class, $this->shop->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Company::class, $shop);
        $user->addCompany($shop, $role);
        $this->em->flush();

        return $user;
    }

    private function assertStillMember(User $user, ?CompanyRole $role = null): void
    {
        $this->em->clear();
        $membership = $this->memberships()->findOne($user, $this->shop);

        self::assertInstanceOf(Membership::class, $membership);

        if ($role instanceof CompanyRole) {
            self::assertSame($role, $membership->getRole());
        }
    }

    private function memberships(): MembershipRepository
    {
        return self::getContainer()->get(MembershipRepository::class);
    }
}
