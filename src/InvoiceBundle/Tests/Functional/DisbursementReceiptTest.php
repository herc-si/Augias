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
use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Action\DisbursementReceipt\ClientDownload;
use Augias\InvoiceBundle\Action\DisbursementReceipt\Delete;
use Augias\InvoiceBundle\Action\DisbursementReceipt\Upload;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Listener\Mailer\DisbursementReceiptListener;
use Augias\InvoiceBundle\Repository\DisbursementReceiptRepository;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function file_exists;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The supplier's document behind a disbursement, from the upload to the
 * client who receives it.
 */
#[CoversClass(Upload::class)]
#[CoversClass(Delete::class)]
#[CoversClass(ClientDownload::class)]
#[CoversClass(DisbursementReceiptListener::class)]
#[Group('functional')]
final class DisbursementReceiptTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Client $customer;

    /** @var list<string> */
    private array $temporaryFiles = [];

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

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAReceiptIsStoredWithTheLineItJustifies(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);

        $this->attach($invoice, 'facture-sncf.pdf');

        $receipts = $this->receipts();

        self::assertCount(1, $receipts);
        self::assertSame('facture-sncf.pdf', $receipts[0]->getFilename());
        self::assertSame('application/pdf', $receipts[0]->getMimeType());
        self::assertTrue($receipts[0]->getLine()->isDisbursement());
        self::assertFileExists($this->storage()->path($receipts[0]->getStoragePath()));
    }

    /**
     * A disbursement with nothing behind it is an ordinary sale in the eyes
     * of the law. The page says so until the document is there.
     */
    public function testTheInvoiceSaysWhenADisbursementHasNoReceipt(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertStringContainsString('No receipt', $crawler->filter('#disbursement-receipts')->text());

        $this->attach($invoice, 'facture.pdf');

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertStringNotContainsString('No receipt', $crawler->filter('#disbursement-receipts')->text());
        self::assertStringContainsString('facture.pdf', $crawler->filter('#disbursement-receipts')->text());
    }

    /**
     * The receipt often arrives after the invoice went out; refusing it then
     * would leave the disbursement unjustified for good.
     */
    public function testAReceiptCanBeAddedToAnIssuedInvoice(): void
    {
        $this->attach($this->invoice(InvoiceStatus::Pending), 'facture.pdf');

        self::assertCount(1, $this->receipts());
    }

    public function testAFileThatIsNotADocumentIsRefused(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        $form = $crawler->filter('#disbursement-receipts')->selectButton('Attach')->form();
        $this->fileField($form)->upload($this->temporaryFile('payload.html', '<html><body>hello</body></html>'));
        $this->client->submit($form);

        self::assertSame([], $this->receipts());
    }

    /**
     * A line that is not a disbursement has no receipt to take — the route
     * does not exist for it.
     */
    public function testALineThatIsNotADisbursementTakesNoReceipt(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);
        $fees = $this->line($invoice, false);

        $this->client->request('POST', '/invoices/disbursement/' . $fees->getId() . '/receipt');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheUserDownloadsTheReceiptUnderItsOwnName(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);
        $this->attach($invoice, 'facture.pdf');

        $this->client->request('GET', '/invoices/disbursement/receipt/' . $this->receipts()[0]->getId());
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('facture.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * The client is not signed in. Their copy lists the receipt, and the link
     * works for them — through the invoice's uuid, the credential the copy
     * itself rests on.
     */
    public function testTheClientDownloadsTheReceiptFromTheirCopy(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending);
        $this->attach($invoice, 'facture.pdf');
        $receipt = $this->receipts()[0];

        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', '/view/invoice/' . $invoice->getUuid());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $link = $crawler->filter('#disbursement-receipts a')->attr('href');
        self::assertSame('/view/invoice/' . $invoice->getUuid() . '/receipt/' . $receipt->getId(), $link);

        $this->client->request('GET', (string) $link);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $this->client->getResponse());
    }

    /**
     * Holding one invoice's link must not open another invoice's receipts.
     */
    public function testAReceiptIsNotServedThroughAnotherInvoice(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending);
        $this->attach($invoice, 'facture.pdf');
        $receipt = $this->receipts()[0];

        $other = $this->invoice(InvoiceStatus::Pending);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/view/invoice/' . $other->getUuid() . '/receipt/' . $receipt->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRemovingAReceiptTakesTheFileWithIt(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);
        $this->attach($invoice, 'facture.pdf');
        $path = $this->storage()->path($this->receipts()[0]->getStoragePath());

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        $this->client->submit($crawler->filter('#disbursement-receipts')->selectButton('Remove this receipt')->form());

        self::assertSame([], $this->receipts());
        self::assertFileDoesNotExist($path);
    }

    /**
     * Once the invoice has gone out, the client may have received the receipt
     * with it. The company has to be able to show what it sent.
     */
    public function testAnIssuedInvoiceKeepsItsReceipts(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);
        $this->attach($invoice, 'facture.pdf');
        $receipt = $this->receipts()[0];

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        $token = (string) $crawler
            ->filter('form[action="/invoices/disbursement/receipt/' . $receipt->getId() . '/delete"] input[name="_token"]')
            ->attr('value');

        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);
        $invoice->setStatus(InvoiceStatus::Paid);
        $this->entityManager->flush();

        $this->client->request('POST', '/invoices/disbursement/receipt/' . $receipt->getId() . '/delete', ['_token' => $token]);

        self::assertCount(1, $this->receipts());
    }

    public function testTheReceiptTravelsWithTheInvoiceEmail(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending);
        $this->attach($invoice, 'facture.pdf');

        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        $email = new InvoiceEmail($invoice);
        $listener = self::getContainer()->get(DisbursementReceiptListener::class);
        self::assertInstanceOf(DisbursementReceiptListener::class, $listener);

        $listener(new MessageEvent($email, new Envelope(new Address('a@example.com'), [new Address('b@example.com')]), 'null://'));

        $names = [];

        foreach ($email->getAttachments() as $attachment) {
            $names[] = $attachment->getFilename();
        }

        self::assertSame(['facture.pdf'], $names);
    }

    /**
     * The mention says the documents are attached only once every
     * disbursement has one — until then they are available on request, which
     * is all the invoice can truthfully promise.
     */
    public function testTheMentionSaysAttachedOnceEveryDisbursementHasItsReceipt(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending);

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertStringContainsString('available on request', $crawler->text());

        $this->attach($invoice, 'facture.pdf');

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertStringContainsString('Supporting documents attached', $crawler->text());
        self::assertStringNotContainsString('available on request', $crawler->text());
    }

    /**
     * The mention the templates print was once defined under a key none of
     * them read, so every invoice showed the key itself. Pinned in both
     * languages.
     */
    public function testTheDisbursementMentionIsTranslated(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['en', 'fr'] as $locale) {
            self::assertStringContainsString('267-II-2°', $translator->trans('invoice.disbursement.mention', [], null, $locale));
        }
    }

    private function attach(Invoice $invoice, string $name): void
    {
        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        $form = $crawler->filter('#disbursement-receipts')->selectButton('Attach')->form();
        $this->fileField($form)->upload($this->temporaryFile($name, self::PDF));

        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    private function invoice(InvoiceStatus $status): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->customer->getId()));
        $invoice->setStatus($status);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-01-05'));
        $invoice->setCompany($this->companyReference());

        foreach ([
            new Line()->setDescription('Consulting')->setPrice(100_000)->setQty(1),
            new Line()->setDescription('Train ticket')->setPrice(12_000)->setQty(1)->setDisbursement(true),
        ] as $line) {
            $line->setCompany($this->companyReference());
            $invoice->addLine($line);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function line(Invoice $invoice, bool $disbursement): Line
    {
        foreach ($invoice->getLines() as $line) {
            if ($line->isDisbursement() === $disbursement) {
                return $line;
            }
        }

        self::fail('No such line.');
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form['receipt'];
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    private function temporaryFile(string $name, string $contents): string
    {
        $directory = sys_get_temp_dir() . '/augias-receipts-' . new Ulid();
        mkdir($directory, 0o700, true);

        $path = $directory . '/' . $name;
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @return list<DisbursementReceipt>
     */
    private function receipts(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(DisbursementReceiptRepository::class)->findBy([], ['created' => 'ASC']);
    }

    private function storage(): DocumentStorage
    {
        $storage = self::getContainer()->get(DocumentStorage::class);
        self::assertInstanceOf(DocumentStorage::class, $storage);

        return $storage;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
