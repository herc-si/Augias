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
use Augias\CoreBundle\Templates\BillingDocumentType;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Action\DisbursementNote\ClientView;
use Augias\InvoiceBundle\Action\DisbursementNote\View;
use Augias\InvoiceBundle\Document\DisbursementNoteRenderer;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use function uniqid;

/**
 * Disbursements go out on a note of their own.
 *
 * EN 16931 forbids a "not subject to VAT" breakdown beside any other
 * (BR-O-11), so an invoice cannot carry fees and disbursements together and
 * still be a valid e-invoice — in France or anywhere else the standard is
 * used. They are still entered on the invoice; the invoice document shows the
 * fees, and the note shows the disbursements.
 *
 * The invoice used throughout: 1 000 of fees at 20 %, so 1 200, and a 500
 * disbursement — 1 700 asked of the client.
 */
#[CoversClass(DisbursementNoteRenderer::class)]
#[CoversClass(View::class)]
#[CoversClass(ClientView::class)]
#[Group('functional')]
final class DisbursementNoteTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Client $customer;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');

        $this->customer = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    public function testTheInvoiceSplitsIntoFeesAndDisbursements(): void
    {
        $invoice = $this->invoice();

        self::assertSame('170000', (string) $invoice->getTotal(), 'The client still owes the whole.');
        self::assertSame('120000', (string) $invoice->getFeesTotal());
        self::assertCount(1, $invoice->getFeeLines());
        self::assertCount(1, $invoice->getDisbursementLines());
        self::assertSame('ND-' . $invoice->getInvoiceId(), $invoice->getDisbursementNoteId());
    }

    /**
     * Half paid is half of each, as the books have it: 600 of the 1 200 of
     * fees and 250 of the 500 advanced still owed, which is the 850 balance.
     */
    public function testAPartialPaymentIsSharedProRata(): void
    {
        $invoice = $this->invoice();
        $invoice->setBalance(85_000);

        self::assertSame('25000', (string) $invoice->getDisbursementBalance());
        self::assertSame('60000', (string) $invoice->getFeesBalance());
        self::assertSame('60000', (string) $invoice->getFeesPaid());
        self::assertSame('25000', (string) $invoice->getDisbursementPaid());
    }

    public function testAFullyPaidInvoiceOwesNothingOnEither(): void
    {
        $invoice = $this->invoice();
        $invoice->setBalance(0);

        self::assertSame('0', (string) $invoice->getDisbursementBalance());
        self::assertSame('0', (string) $invoice->getFeesBalance());
    }

    /**
     * Every design the company can choose, PDF and on-screen alike: the fees,
     * their total, and a pointer to the note — never the disbursement itself.
     */
    public function testEveryInvoiceTemplateShowsTheFeesAndPointsToTheNote(): void
    {
        $invoice = $this->invoice();
        $registry = self::getContainer()->get(BillingTemplateRegistry::class);
        $twig = self::getContainer()->get(Environment::class);

        $templates = [BillingTemplateResolver::defaultTemplate(BillingDocumentType::Invoice, BillingTemplateChannel::Pdf)];

        foreach ($registry->getSlugs() as $slug) {
            foreach ([BillingTemplateChannel::Pdf, BillingTemplateChannel::View] as $channel) {
                $path = $registry->templatePath($slug, BillingDocumentType::Invoice, $channel);

                if (null !== $path) {
                    $templates[] = $path;
                }
            }
        }

        self::assertGreaterThan(8, count($templates));

        foreach ($templates as $template) {
            $html = $twig->render($template, ['invoice' => $invoice]);

            self::assertStringContainsString('Consulting', $html, $template);
            self::assertStringNotContainsString('Screen bought for the client', $html, $template . ' shows the disbursement.');
            self::assertStringContainsString($invoice->getDisbursementNoteId(), $html, $template . ' does not point to the note.');
            self::assertStringNotContainsString('1,700.00', $html, $template . ' shows the combined total as the invoice\'s.');
        }
    }

    public function testTheNoteCarriesTheDisbursementsAndTheirGround(): void
    {
        $invoice = $this->invoice();

        $html = self::getContainer()->get(Environment::class)->render(DisbursementNoteRenderer::TEMPLATE, ['invoice' => $invoice]);

        self::assertStringContainsString($invoice->getDisbursementNoteId(), $html);
        self::assertStringContainsString('Screen bought for the client', $html);
        self::assertStringNotContainsString('Consulting', $html);
        self::assertStringContainsString('500.00', $html);
        self::assertStringContainsString('267-II-2°', $html);
    }

    public function testTheUserOpensTheNoteAsAPdf(): void
    {
        $invoice = $this->invoice();

        $this->client->request('GET', '/invoices/view/' . $invoice->getId() . '/disbursement-note.pdf');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString($invoice->getDisbursementNoteId() . '.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAnInvoiceWithoutDisbursementsHasNoNote(): void
    {
        $invoice = $this->invoice(false);

        $this->client->request('GET', '/invoices/view/' . $invoice->getId() . '/disbursement-note.pdf');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The client, not signed in, finds the note on their copy of the invoice
     * and what they owe in all — both documents together.
     */
    public function testTheClientFindsTheNoteAndTheAmountDueOnTheirCopy(): void
    {
        $invoice = $this->invoice();

        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', '/view/invoice/' . $invoice->getUuid());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $note = $crawler->filter('#disbursement-note');
        self::assertStringContainsString('Screen bought for the client', $note->text());
        self::assertStringContainsString('1,700.00', $crawler->filter('#amount-due')->text());

        $this->client->request('GET', '/view/invoice/' . $invoice->getUuid() . '/disbursement-note.pdf');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    private function invoice(bool $withDisbursement = true): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->customer->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-01-05'));
        $invoice->setCompany($this->companyReference());

        $fees = new Line()->setDescription('Consulting')->setPrice(100_000)->setQty(1)->addTax(
            new LineTax()
                ->setNameSnapshot('VAT')
                ->setRateSnapshot('20')
                ->setTypeSnapshot(TaxType::Exclusive)
                ->setCategorySnapshot(TaxCategory::Standard),
        );
        $lines = [$fees];

        if ($withDisbursement) {
            $lines[] = new Line()->setDescription('Screen bought for the client')->setPrice(50_000)->setQty(1)->setDisbursement(true);
        }

        foreach ($lines as $line) {
            $line->setCompany($this->companyReference());
            $invoice->addLine($line);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
