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

namespace Augias\InvoiceBundle\Tests\Functional;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\UserBundle\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use function assert;

/**
 * The credit note screens end to end. The unit tests prove the document and the
 * numbering behave; this proves the pages actually render and that the one rule
 * that matters — an issued credit note stops being editable — holds at the URL,
 * not merely in the buttons.
 */
#[Group('functional')]
final class CreditNoteFlowTest extends WebTestCase
{
    use DoctrineTestTrait;
    use EnsureApplicationInstalled;
    use HasBrowser;

    private ?Client $client = null;

    public function testListsCreditNotes(): void
    {
        CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'creditNoteId' => 'AV-0001-2026',
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/invoices/credit-notes')
            ->assertSuccessful()
            ->assertSeeIn('body', 'AV-0001-2026');
    }

    public function testOpensAnEmptyForm(): void
    {
        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/invoices/credit-notes/create')
            ->assertSuccessful();
    }

    /**
     * Reached from an invoice, the form arrives with that invoice's lines
     * already mirrored — re-typing them is how the two documents drift apart.
     */
    public function testOpensPreFilledFromAnInvoice(): void
    {
        $invoice = InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'lines' => [
                new \Augias\InvoiceBundle\Entity\Line()
                    ->setDescription('Annual licence')
                    ->setPrice(9900)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/invoices/credit-notes/create/' . $invoice->getId())
            ->assertSuccessful()
            ->assertSeeIn('body', 'Annual licence');
    }

    public function testShowsACreditNote(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'creditNoteId' => 'AV-0007-2026',
            'reason' => CreditReason::Return,
            'lines' => [
                new CreditNoteLine()
                    ->setDescription('Returned licence')
                    ->setPrice(9900)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/invoices/credit-notes/view/' . $creditNote->getId())
            ->assertSuccessful()
            ->assertSeeIn('body', 'AV-0007-2026')
            ->assertSeeIn('body', 'Returned licence');
    }

    public function testLetsADraftBeEdited(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Draft,
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/invoices/credit-notes/edit/' . $creditNote->getId())
            ->assertSuccessful();
    }

    /**
     * The button is hidden, but the URL is reachable by hand — which is the
     * only reason this assertion is worth making.
     */
    public function testRefusesToEditAnIssuedCreditNote(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Issued,
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->interceptRedirects()
            ->visit('/invoices/credit-notes/edit/' . $creditNote->getId())
            ->assertRedirectedTo('/invoices/credit-notes/view/' . $creditNote->getId());
    }

    public function testIssuingFixesTheDocument(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Draft,
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->interceptRedirects()
            ->visit('/invoices/credit-notes/action/issue/' . $creditNote->getId())
            ->assertRedirectedTo('/invoices/credit-notes/view/' . $creditNote->getId());

        $this->em->clear();

        $reloaded = $this->em->find(CreditNote::class, $creditNote->getId());

        self::assertInstanceOf(CreditNote::class, $reloaded);
        self::assertSame(CreditNoteStatus::Issued, $reloaded->getStatus());
        self::assertNotNull($reloaded->getIssuedAt());
    }

    /**
     * The client's own page is where someone asks "what has this client been
     * credited", so the tab has to be there — and only once there is something
     * behind it.
     */
    public function testShowsTheCreditNotesTabOnTheClientPage(): void
    {
        // Before the browser boots: it starts a kernel of its own, and a client
        // created afterwards lands with no company on its credit row.
        $client = $this->client();

        $browser = $this->browser()->actingAs($this->createUser());

        $browser
            ->visit('/clients/view/' . $client->getId())
            ->assertSuccessful()
            ->assertElementCount('#credit-notes-tab', 0);

        CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'creditNoteId' => 'AV-0042-2026',
        ]);

        $browser
            ->visit('/clients/view/' . $client->getId())
            ->assertSuccessful()
            ->assertElementCount('#credit-notes-tab', 1)
            ->assertSeeIn('#credit-notes-tab', 'Credit Notes');
    }

    /**
     * The settlement panel is the whole point of the document page once the
     * credit note is out: it is where a refund or an offset gets written down.
     */
    public function testRecordsARefundFromTheDocumentPage(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Issued,
            'discount' => new Discount(),
            'lines' => [
                new CreditNoteLine()
                    ->setDescription('Returned licence')
                    ->setPrice(9900)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $this->browser()
            ->actingAs($this->createUser())
            ->interceptRedirects()
            ->visit('/invoices/credit-notes/view/' . $creditNote->getId())
            ->assertSuccessful()
            ->fillField('credit_note_allocation[amount]', '99.00')
            ->selectFieldOption('credit_note_allocation[kind]', 'refund')
            ->click('Record')
            ->assertRedirectedTo('/invoices/credit-notes/view/' . $creditNote->getId());

        $this->em->clear();

        $reloaded = $this->em->find(CreditNote::class, $creditNote->getId());

        self::assertInstanceOf(CreditNote::class, $reloaded);
        self::assertCount(1, $reloaded->getAllocations());
        self::assertSame('9900', (string) $reloaded->getAllocations()->first()->getAmount());

        // Using it up in one go settles it.
        self::assertSame(CreditNoteStatus::Settled, $reloaded->getStatus());
    }

    /**
     * Created on demand rather than in setUp(): the company this belongs to is
     * installed by a #[Before] hook, and ordering the two reliably is not worth
     * the trouble when a lazy accessor says the same thing.
     */
    /**
     * The tile reaches the page it was written for.
     *
     * Its own test proves the numbers and the markup; this proves the part
     * neither of them can — that the widget registers, that the resolver puts
     * it in the top zone for a user who has never customised anything, and that
     * it appears at all once a credit note has been issued.
     */
    public function testTheCreditTileAppearsOnTheDashboardOnceACreditNoteIsIssued(): void
    {
        $this->browser()
            ->actingAs($this->createUser())
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertNotSeeElement('[data-widget-id="credit_notes_total"]');

        CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Issued,
        ]);

        $this->browser()
            ->actingAs($this->createUser('credit-tile@example.com'))
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertSeeElement('[data-widget-id="credit_notes_total"][data-widget-width="quarter"]');
    }

    private function client(): Client
    {
        // Pinned: ClientFactory leaves the currency to Faker, which happily
        // produces codes moneyphp has never heard of (ANG, demonetised) and
        // ones with a different subunit, and these tests both render money and
        // type an amount in major units.
        return $this->client ??= ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
    }

    private function createUser(string $email = 'credit-notes@example.com', #[SensitiveParameter] string $password = 'password'): User
    {
        // Looked up through this test's own manager: the company is installed
        // by a #[Before] hook, and the instance it left behind is detached from
        // the manager the browser boots with. Handing Doctrine the detached one
        // makes it try to persist the company a second time.
        $company = $this->em->find(Company::class, $this->company->getId());
        assert($company instanceof Company);

        $user = new User();
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setVerified(true);
        $user->addCompany($company);

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
