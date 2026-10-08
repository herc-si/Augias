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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;
use function json_decode;
use function json_encode;

/**
 * Finalising a draft from its edit page, as the browser does it: the page's
 * own live props sent back with the action.
 *
 * A draft has no number, so its string is empty, and the page handed it to
 * the form through "|default(null)" — which Twig reads as empty and turns
 * into null. Every save from the edit page failed with a 500 (app-test,
 * 08/10/2026); the component tests, which hand the invoice over directly,
 * never saw it.
 */
#[CoversNothing]
final class EditDraftTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    public function testADraftIsFinalisedFromItsEditPage(): void
    {
        $id = $this->draft();

        $crawler = $this->client->request('GET', '/invoices/edit/' . $id);
        self::assertResponseIsSuccessful();

        $props = json_decode((string) $crawler->filter('[data-live-name-value="CreateInvoice"]')->attr('data-live-props-value'), true);
        self::assertIsArray($props);
        self::assertSame((string) $id, $props['invoiceEntity'], 'The edit form holds the invoice it edits.');

        $this->client->request(
            'POST',
            '/_components/CreateInvoice/savePublish',
            ['data' => json_encode(['props' => $props, 'updated' => (object) [], 'args' => (object) []])],
            [],
            ['HTTP_ACCEPT' => 'application/vnd.live-component+html'],
        );

        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $invoice = $entityManager->find(Invoice::class, $id);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(InvoiceStatus::Pending, $invoice->getStatus());
        self::assertTrue($invoice->isNumbered());
    }

    private function draft(): Ulid
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['company' => $this->company, 'email' => 'client@example.org', 'client' => $client]);

        $company = $entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setStatus(InvoiceStatus::Draft);
        $invoice->setClient($client);
        $invoice->setInvoiceDate(CarbonImmutable::parse('2026-10-08'));
        $invoice->addUser($contact);
        $invoice->addLine(new Line()->setDescription('Prestation')->setPrice(10000)->setQty(1)->setTotal(10000));
        $entityManager->persist($invoice);
        $entityManager->flush();

        $id = $invoice->getId();
        self::assertInstanceOf(Ulid::class, $id);
        $entityManager->clear();

        return $id;
    }
}
