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
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\Action\CreateFromQuote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Twig\Components\CreateInvoice;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "Create the invoice" on an accepted quote: the form, filled from the quote,
 * with nothing saved and no number taken until it is.
 */
#[CoversClass(CreateFromQuote::class)]
final class CreateFromQuoteTest extends LiveComponentTest
{
    public function testTheFormOpensWithTheQuotesContentAndSavesNothing(): void
    {
        $quote = $this->createQuote(QuoteStatus::Accepted);
        $this->client->loginUser($this->getUser());

        $this->client->request('GET', '/invoices/from-quote/' . $quote->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Rénovation de la cuisine', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager()->getRepository(Invoice::class)->count(['quote' => $quote->getId()]));
    }

    public function testSavingTiesTheInvoiceToTheQuote(): void
    {
        $quote = $this->createQuote(QuoteStatus::Accepted);

        $invoiceManager = self::getContainer()->get(InvoiceManager::class);
        self::assertInstanceOf(InvoiceManager::class, $invoiceManager);
        $formManager = self::getContainer()->get(InvoiceFormManager::class);
        self::assertInstanceOf(InvoiceFormManager::class, $formManager);

        $draft = $invoiceManager->draftFromQuote($quote);
        self::assertSame('', $draft->getInvoiceId(), 'No number before the form is saved.');

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $formManager->createDTOFromInvoice($draft), 'fromQuote' => $quote],
            client: $this->client,
        )->actingAs($this->getUser());

        $component->call('saveDraft');

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $fresh = $entityManager->find(Quote::class, $quote->getId());
        self::assertInstanceOf(Quote::class, $fresh);
        self::assertInstanceOf(Invoice::class, $fresh->getInvoice());
        self::assertNotSame('', $fresh->getInvoice()->getInvoiceId());
    }

    public function testAQuoteThatIsNotAcceptedIsNotInvoiced(): void
    {
        $quote = $this->createQuote(QuoteStatus::Pending);
        $this->client->loginUser($this->getUser());

        $this->client->request('GET', '/invoices/from-quote/' . $quote->getId());

        self::assertTrue($this->client->getResponse()->isRedirect('/quotes/view/' . $quote->getId()));
    }

    private function createQuote(QuoteStatus $status): Quote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['client' => $client, 'company' => $this->company, 'email' => 'client@example.org']);

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => $status,
            'archived' => null,
            'users' => [$contact],
            'lines' => [
                new Line()
                    ->setDescription('Rénovation de la cuisine')
                    ->setPrice(BigInteger::of(120000))
                    ->setQty(1)
                    ->setTotal(BigInteger::of(120000)),
            ],
        ]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
