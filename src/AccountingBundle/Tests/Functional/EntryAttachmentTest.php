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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Action\Entry\DeleteAttachment;
use Augias\AccountingBundle\Action\Entry\DownloadAttachment;
use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Repository\EntryAttachmentRepository;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AttachmentStorage;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Uid\Ulid;
use function file_exists;
use function file_put_contents;
use function hash;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * The document behind an entry, from the upload to the disk and back.
 *
 * Driven through the real pages, because most of what this feature is lives in
 * the seam between a form, a file on disk and a row that ties them together.
 */
#[CoversClass(AttachmentStorage::class)]
#[CoversClass(DownloadAttachment::class)]
#[CoversClass(DeleteAttachment::class)]
#[Group('functional')]
final class EntryAttachmentTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

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

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, 'fr_micro');
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
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

    public function testADocumentIsStoredWithTheEntryItBelongsTo(): void
    {
        $this->addEntryWith($this->pdf('facture.pdf'));

        $attachments = $this->attachments();

        self::assertCount(1, $attachments);

        $attachment = $attachments[0];

        // The name the user knows is kept as a label, and nowhere else.
        self::assertSame('facture.pdf', $attachment->getFilename());
        self::assertSame('application/pdf', $attachment->getMimeType());
        self::assertSame(hash('sha256', self::PDF), $attachment->getChecksum());

        // By company, then by year, under a name of its own — see
        // AttachmentStorage for why none of it comes from the upload.
        self::assertMatchesRegularExpression(
            '#^' . $this->companyReference()->getId() . '/' . new DateTimeImmutable('today')->format('Y') . '/[0-9A-HJKMNP-TV-Z]{26}\.pdf$#',
            $attachment->getStoragePath(),
        );
        self::assertFileExists($this->storage()->path($attachment));
    }

    public function testADocumentComesBackUnderTheNameItWasUploadedWith(): void
    {
        $this->addEntryWith($this->pdf('facture.pdf'));

        $attachment = $this->attachments()[0];

        $this->client->request('GET', '/accounting/entry/attachment/' . $attachment->getId());
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        // As a download, never inline: this is a file the application did not
        // write, and rendering it in the page would put it inside this origin.
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('facture.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testRemovingADocumentTakesTheFileWithIt(): void
    {
        $this->addEntryWith($this->pdf('facture.pdf'));

        $attachment = $this->attachments()[0];
        $path = $this->storage()->path($attachment);
        $entry = $this->entries()[0];

        $crawler = $this->client->request('GET', '/accounting/entry/' . $entry->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Remove this document')->form());

        self::assertSame([], $this->attachments());
        self::assertFileDoesNotExist($path, 'The row went; the document has to go with it.');
    }

    /**
     * A sealed entry cannot lose its evidence. The seal says what was recorded
     * then can no longer change, and an entry whose document can still be taken
     * away is not sealed in any sense worth having.
     */
    public function testASealedEntryKeepsItsDocuments(): void
    {
        $this->addEntryWith($this->pdf('facture.pdf'));

        $attachment = $this->attachments()[0];
        $entry = $this->entries()[0];

        // The token is taken off the page that offers the button, the way a
        // browser would send it: sealing afterwards is what this test is about,
        // and a request refused for the wrong reason would prove nothing.
        $crawler = $this->client->request('GET', '/accounting/entry/' . $entry->getId() . '/edit');
        $token = (string) $crawler
            ->filter('button[formaction="/accounting/entry/attachment/' . $attachment->getId() . '/delete"]')
            ->attr('value');

        $entry = $this->entityManager->find(LedgerEntry::class, $entry->getId());
        self::assertInstanceOf(LedgerEntry::class, $entry);
        $entry->setLockedAt(new DateTimeImmutable('today'));
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/accounting/entry/attachment/' . $attachment->getId() . '/delete',
            ['_token' => $token],
        );

        self::assertCount(1, $this->attachments(), 'The document is still there.');
    }

    /**
     * A supporting document is a document. Anything else is refused before it
     * reaches the disk, and the entry is not written either.
     */
    public function testAFileThatIsNotADocumentIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/accounting/book/revenue/entry/add');

        $form = $crawler->selectButton('Save')->form($this->entryFields());
        $form['ledger_entry[files]'][0]->upload($this->temporaryFile('payload.html', '<html><body>hello</body></html>'));

        $this->client->submit($form);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Redisplayed rather than saved.');
        self::assertSame([], $this->entries());
        self::assertSame([], $this->attachments());
    }

    /**
     * @return array<string, string>
     */
    private function entryFields(): array
    {
        return [
            'ledger_entry[entryDate]' => '2026-02-10',
            'ledger_entry[label]' => 'Cash sale',
            'ledger_entry[counterpartyName]' => 'A passer-by',
            'ledger_entry[documentReference]' => 'REC-003',
            'ledger_entry[amount]' => '120.00',
            'ledger_entry[settlementMethod]' => 'cash',
            'ledger_entry[activityNature]' => 'services_bnc',
        ];
    }

    private function addEntryWith(string $file): void
    {
        $crawler = $this->client->request('GET', '/accounting/book/revenue/entry/add');

        $form = $crawler->selectButton('Save')->form($this->entryFields());
        $form['ledger_entry[files]'][0]->upload($file);

        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    private function pdf(string $name): string
    {
        return $this->temporaryFile($name, self::PDF);
    }

    /**
     * Written under a directory of its own so the file keeps exactly the name
     * asked for: the name is what the upload carries, and the test is about
     * what happens to it.
     */
    private function temporaryFile(string $name, string $contents): string
    {
        $directory = sys_get_temp_dir() . '/augias-attachments-' . new Ulid();
        mkdir($directory, 0o700, true);

        $path = $directory . '/' . $name;
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @return list<EntryAttachment>
     */
    private function attachments(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(EntryAttachmentRepository::class)->findBy([], ['created' => 'ASC']);
    }

    /**
     * @return list<LedgerEntry>
     */
    private function entries(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(LedgerEntryRepository::class)->findBy([], ['entryDate' => 'ASC']);
    }

    private function storage(): AttachmentStorage
    {
        $storage = self::getContainer()->get(AttachmentStorage::class);
        self::assertInstanceOf(AttachmentStorage::class, $storage);

        return $storage;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
