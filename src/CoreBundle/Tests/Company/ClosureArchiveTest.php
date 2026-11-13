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

namespace Augias\CoreBundle\Tests\Company;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\ClosureArchive;
use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Company\CompanyClosureNotifier;
use Augias\CoreBundle\Company\CompanyPurgeContext;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\ExportJob;
use Augias\CoreBundle\Export\Enum\ExportStatus;
use Augias\CoreBundle\Export\Message\Handler\ProcessCompanyExportHandler;
use Augias\CoreBundle\Export\Message\RequestCompanyExport;
use Augias\CoreBundle\Export\Security\Voter\ExportJobVoter;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Repository\ExportJobRepository;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Test\Factory\UserFactory;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Zenstruck\Mailer\Test\InteractsWithMailer;
use function dirname;

/**
 * A closing company is handed what it has to keep without having to ask,
 * and does not go before that archive exists.
 */
#[CoversClass(ClosureArchive::class)]
#[CoversClass(CompanyClosure::class)]
#[CoversClass(ExportJobVoter::class)]
final class ClosureArchiveTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use InteractsWithMailer;

    public function testSchedulingAClosureStartsTheArchive(): void
    {
        $company = $this->shopRunBy('owner@archive.test');

        $this->closure('2026-09-26 10:00:00')->schedule($company);

        $archives = $this->jobs()->findClosureArchives($company);
        self::assertCount(1, $archives);
        self::assertSame(ExportStatus::Pending, $archives[0]->getStatus());
        self::assertTrue($archives[0]->isForClosure());

        self::assertFalse($this->archive()->prepare($company), 'One on its way is enough.');
        self::assertCount(1, $this->jobs()->findClosureArchives($company));
    }

    public function testOnceMadeTheArchiveIsSentToThoseWhoRunTheCompany(): void
    {
        $company = $this->shopRunBy('owner@archive.test');
        $this->closure('2026-09-26 10:00:00')->schedule($company);

        $job = $this->make($company);

        self::assertSame(ExportStatus::Completed, $job->getStatus());
        $this->mailer()->sentEmails()->assertCount(2);
        $this->mailer()->sentEmails()->last()
            ->assertTo('owner@archive.test')
            ->assertSubject("Closing Shop's archive is ready")
            ->assertContains('/profile/exports/' . $job->getId()->toBase58() . '/download');
    }

    public function testACompanyWaitsForItsArchiveBeforeItGoes(): void
    {
        $company = $this->shopRunBy('owner@archive.test');
        $companyId = $company->getId();
        $this->closure('2026-09-26 10:00:00')->schedule($company);

        $late = $this->closure('2026-10-27 00:00:00');
        self::assertSame(['Closing Shop'], $late->prepareArchives());
        self::assertSame([], $late->purgeDue(), 'Its invoices would be lost: nobody else keeps them.');

        $job = $this->make($company);
        $directory = dirname((string) $job->resolveAbsolutePath($this->projectDir()));
        self::assertDirectoryExists($directory);

        self::assertSame([], $late->prepareArchives());
        self::assertSame(['Closing Shop'], $late->purgeDue());

        $this->em()->clear();
        self::assertNull($this->em()->find(Company::class, $companyId));
        self::assertDirectoryDoesNotExist($directory, 'The archive holds what the closure deletes.');
    }

    public function testACompanyNobodyRunsDoesNotWait(): void
    {
        $company = $this->shop();
        $company->scheduleClosure(CarbonImmutable::parse('2026-10-26 10:00:00'));
        $this->em()->flush();

        $late = $this->closure('2026-10-27 00:00:00');

        self::assertSame([], $late->prepareArchives());
        self::assertSame(['Closing Shop'], $late->purgeDue());
    }

    /**
     * Between two closures the company was writable again: what the first
     * archive holds is no longer everything.
     */
    public function testTheArchiveOfAClosureCalledOffDoesNotCount(): void
    {
        $company = $this->shopRunBy('owner@archive.test');
        $closure = $this->closure('2026-09-26 10:00:00');
        $closure->schedule($company);

        $first = $this->make($company);
        self::assertTrue($this->archive()->isSettled($company));

        $closure->cancel($company);
        // The archive is dated by the real clock: tell the two closures apart.
        $this->em()->getConnection()->executeStatement('UPDATE export_jobs SET created_at = :then', ['then' => '2026-01-01 00:00:00']);
        $this->em()->clear();
        $company = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $closure->schedule($company);

        self::assertFalse($this->archive()->isSettled($company));
        self::assertCount(2, $this->jobs()->findClosureArchives($company));

        new Filesystem()->remove(dirname((string) $first->resolveAbsolutePath($this->projectDir())));
    }

    public function testAnyoneWhoRunsTheCompanyMayTakeItsArchive(): void
    {
        $company = $this->shopRunBy('owner@archive.test');
        $admin = $this->member($company, 'admin@archive.test', CompanyRole::Admin);
        $accountant = $this->member($company, 'reader@archive.test', CompanyRole::Accountant);
        $this->closure('2026-09-26 10:00:00')->schedule($company);
        $job = $this->jobs()->findClosureArchives($company)[0];

        self::getContainer()->get(CompanySelector::class)->switchCompany($company->getId());
        $voter = self::getContainer()->get(ExportJobVoter::class);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($admin), $job, [ExportJobVoter::DOWNLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenFor($accountant), $job, [ExportJobVoter::DOWNLOAD]));
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::getContainer()->get('security.token_storage')->setToken($token);
        self::getContainer()->get(CompanyAccess::class)->reset();

        return $token;
    }

    /**
     * Does what the worker would: runs the export waiting for this company.
     */
    private function make(Company $company): ExportJob
    {
        $job = $this->jobs()->findClosureArchives($company)[0];

        self::getContainer()->get(ProcessCompanyExportHandler::class)(
            new RequestCompanyExport($job->getId(), $company->getId(), $job->getRequestedBy()),
        );

        $this->em()->refresh($job);

        return $job;
    }

    private function shopRunBy(string $email): Company
    {
        $company = $this->shop();
        $this->member($company, $email, CompanyRole::Owner);

        return $company;
    }

    private function member(Company $company, string $email, CompanyRole $role): User
    {
        $user = UserFactory::createOne(['email' => $email, 'companies' => []]);
        $user = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->addCompany($company, $role);
        $this->em()->flush();
        $this->em()->refresh($company);

        return $user;
    }

    private function shop(): Company
    {
        $company = CompanyFactory::createOne(['name' => 'Closing Shop']);
        $client = ClientFactory::createOne(['company' => $company]);
        InvoiceFactory::createOne(['company' => $company, 'client' => $client, 'status' => InvoiceStatus::Paid, 'invoiceId' => 'CLOSE-1']);

        $managed = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $managed);

        return $managed;
    }

    private function closure(string $now): CompanyClosure
    {
        $container = self::getContainer();

        return new CompanyClosure(
            $this->em(),
            $container->get(CompanyRepository::class),
            new MockClock($now),
            $container->get(CompanyClosureNotifier::class),
            $container->get(CompanyPurgeContext::class),
            $this->archive(),
        );
    }

    private function archive(): ClosureArchive
    {
        return self::getContainer()->get(ClosureArchive::class);
    }

    private function jobs(): ExportJobRepository
    {
        return self::getContainer()->get(ExportJobRepository::class);
    }

    private function projectDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
