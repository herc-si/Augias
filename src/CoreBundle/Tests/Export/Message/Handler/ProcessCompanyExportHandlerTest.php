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

namespace Augias\CoreBundle\Tests\Export\Message\Handler;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\ExportJob;
use Augias\CoreBundle\Export\CompanyExporter;
use Augias\CoreBundle\Export\Discovery\EntityDiscovery;
use Augias\CoreBundle\Export\Enum\ExportFormat;
use Augias\CoreBundle\Export\Enum\ExportStatus;
use Augias\CoreBundle\Export\Message\Handler\ProcessCompanyExportHandler;
use Augias\CoreBundle\Export\Message\RequestCompanyExport;
use Augias\CoreBundle\Repository\ExportJobRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Mailer\Test\InteractsWithMailer;
use ZipArchive;

#[CoversClass(ProcessCompanyExportHandler::class)]
#[CoversClass(CompanyExporter::class)]
#[CoversClass(EntityDiscovery::class)]
final class ProcessCompanyExportHandlerTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use InteractsWithMailer;

    public function testHandlerBuildsArchiveAndNotifiesUser(): void
    {
        $user = UserFactory::createOne([
            'email' => 'exporter@example.com',
            'companies' => [$this->company],
        ]);

        $repository = $this->exportJobRepository();
        $job = new ExportJob($user->getId(), ExportFormat::Json)->setCompany($this->company);
        $repository->save($job);

        $handler = self::getContainer()->get(ProcessCompanyExportHandler::class);
        $handler(new RequestCompanyExport($job->getId(), $this->company->getId(), $user->getId()));

        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        $reloaded = $repository->find($job->getId());
        self::assertInstanceOf(ExportJob::class, $reloaded);
        self::assertSame(ExportStatus::Completed, $reloaded->getStatus());
        self::assertNotNull($reloaded->getArchivePath());

        $absolutePath = $reloaded->resolveAbsolutePath(self::getContainer()->getParameter('kernel.project_dir'));
        self::assertNotNull($absolutePath);
        self::assertFileExists($absolutePath);

        $this->assertArchiveContainsManifest($absolutePath);

        $this->mailer()->sentEmails()->assertCount(1);
        $this->mailer()->sentEmails()->first()->assertTo('exporter@example.com');

        new Filesystem()->remove($absolutePath);
    }

    /**
     * The archive carries the documents a company has to keep, as issued —
     * not only the rows. Drafts were never issued and stay out.
     */
    public function testTheArchiveHoldsTheIssuedInvoicesAsPdf(): void
    {
        $user = UserFactory::createOne(['email' => 'archive@example.com', 'companies' => [$this->company]]);
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Paid, 'invoiceId' => 'EXP-1']);
        InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Draft, 'invoiceId' => 'EXP-DRAFT']);

        $repository = $this->exportJobRepository();
        $job = new ExportJob($user->getId(), ExportFormat::Json)->setCompany($this->company);
        $repository->save($job);

        self::getContainer()->get(ProcessCompanyExportHandler::class)(new RequestCompanyExport($job->getId(), $this->company->getId(), $user->getId()));
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        $reloaded = $repository->find($job->getId());
        self::assertInstanceOf(ExportJob::class, $reloaded);
        self::assertSame(ExportStatus::Completed, $reloaded->getStatus(), (string) $reloaded->getFailureReason());
        $path = $reloaded->resolveAbsolutePath(self::getContainer()->getParameter('kernel.project_dir'));
        self::assertNotNull($path);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $pdf = $zip->getFromName('files/invoices/EXP-1.pdf');
        $draft = $zip->locateName('files/invoices/EXP-DRAFT.pdf');
        $zip->close();
        new Filesystem()->remove($path);

        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertFalse($draft);
    }

    public function testSkipsWhenJobIsNotPending(): void
    {
        $user = UserFactory::createOne([
            'email' => 'exporter-idempotent@example.com',
            'companies' => [$this->company],
        ]);

        $repository = $this->exportJobRepository();
        $job = new ExportJob($user->getId(), ExportFormat::Json)->setCompany($this->company);
        $job->markFailed('pre-existing failure');

        $repository->save($job);

        $handler = self::getContainer()->get(ProcessCompanyExportHandler::class);
        $handler(new RequestCompanyExport($job->getId(), $this->company->getId(), $user->getId()));

        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        $reloaded = $repository->find($job->getId());
        self::assertInstanceOf(ExportJob::class, $reloaded);
        self::assertSame(ExportStatus::Failed, $reloaded->getStatus());
        self::assertSame('pre-existing failure', $reloaded->getFailureReason());
    }

    private function exportJobRepository(): ExportJobRepository
    {
        /** @var ExportJobRepository $repository */
        $repository = self::getContainer()->get(ExportJobRepository::class);
        return $repository;
    }

    private function assertArchiveContainsManifest(string $archivePath): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archivePath));

        $manifestContents = $zip->getFromName('manifest.json');
        $zip->close();

        self::assertNotFalse($manifestContents);

        $manifest = json_decode($manifestContents, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('augias_version', $manifest);
        self::assertArrayHasKey('entity_counts', $manifest);
    }
}
