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
use Augias\AccountingBundle\Action\CreatePeriod;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodStatus;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(CreatePeriod::class)]
final class CreatePeriodTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    /**
     * The quarter nothing was booked into: created open and at zero, so the
     * ordinary close-declare-record flow can give the nil return a history.
     */
    public function testCreatesTheQuarterADateFallsIn(): void
    {
        $response = $this->post('2026-05-15');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());

        $period = $this->findQuarter(2026, 2);

        self::assertInstanceOf(AccountingPeriod::class, $period);
        self::assertSame(PeriodStatus::Open, $period->getStatus());
        self::assertSame('2026-04-01', $period->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-06-30', $period->getEndDate()->format('Y-m-d'));
        self::assertSame(0, $period->getEntryCount());
    }

    /**
     * A double submit, or a button left on a stale page, must not produce two
     * rows for one quarter — periodFor() hands back the existing one.
     */
    public function testCreatingTheSameQuarterTwiceCreatesOneRow(): void
    {
        $this->post('2026-05-15');
        $this->post('2026-06-02');

        self::assertCount(1, $this->repository()->findForYear($this->companyReference(), PeriodType::Quarter, 2026));
    }

    /**
     * The same quarter asked for twice inside one unit of work, with no flush in
     * between — two payments booked in the same quarter, which is what the demo
     * data loader does, and what a batch import would do.
     *
     * findForDate() is a DQL query and so only sees what the database already
     * holds: the period persisted a moment ago is invisible to it. Without the
     * manager remembering what it has just built, this produced two rows and the
     * flush died on "UNIQUE constraint failed: accounting_periods.company_id,
     * period_type, period_year, period_ordinal".
     */
    public function testTheSameQuarterAskedForTwiceBeforeAFlushIsOnePeriod(): void
    {
        $manager = self::getContainer()->get(AccountingPeriodManager::class);
        self::assertInstanceOf(AccountingPeriodManager::class, $manager);

        $company = $this->companyReference();

        $first = $manager->periodFor($company, PeriodType::Quarter, new DateTimeImmutable('2026-05-15'));
        $second = $manager->periodFor($company, PeriodType::Quarter, new DateTimeImmutable('2026-06-02'));

        self::assertSame($first, $second, 'the second call must hand back the period the first one built');

        $this->entityManager->flush();

        self::assertCount(1, $this->repository()->findForYear($this->companyReference(), PeriodType::Quarter, 2026));
    }

    public function testRejectsAnInvalidToken(): void
    {
        $response = $this->post('2026-05-15', validToken: false);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertNull($this->findQuarter(2026, 2));
    }

    /**
     * A date the application cannot read writes nothing, rather than creating a
     * period for whatever the parser felt like.
     */
    public function testRejectsADateItCannotRead(): void
    {
        $response = $this->post('not a date at all');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame([], $this->repository()->findForYear($this->companyReference(), PeriodType::Quarter, 2026));
    }

    public function testDoesNothingWithoutARegime(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::REGIME, '');

        $this->post('2026-05-15');

        self::assertNull($this->findQuarter(2026, 2));
    }

    private function post(string $date, bool $validToken = true): Response
    {
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturn($validToken);

        $action = new CreatePeriod(
            self::getContainer()->get(AccountingProfileProvider::class),
            self::getContainer()->get(RegimeRegistry::class),
            self::getContainer()->get(CurrentCompany::class),
            self::getContainer()->get(AccountingPeriodManager::class),
            $this->entityManager,
            $csrfTokenManager,
            self::getContainer()->get('router'),
        );

        $request = new Request(request: ['_token' => 'token', 'date' => $date]);

        return $action($request, new Session(new MockArraySessionStorage()));
    }

    private function findQuarter(int $year, int $ordinal): ?AccountingPeriod
    {
        $this->entityManager->clear();

        foreach ($this->repository()->findForYear($this->companyReference(), PeriodType::Quarter, $year) as $period) {
            if ($period->getOrdinal() === $ordinal) {
                return $period;
            }
        }

        return null;
    }

    private function repository(): AccountingPeriodRepository
    {
        $repository = self::getContainer()->get(AccountingPeriodRepository::class);
        self::assertInstanceOf(AccountingPeriodRepository::class, $repository);

        return $repository;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
