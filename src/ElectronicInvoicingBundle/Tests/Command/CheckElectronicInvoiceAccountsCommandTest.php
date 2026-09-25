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

namespace Augias\ElectronicInvoicingBundle\Tests\Command;

use Augias\CoreBundle\Test\Traits\ConsoleTesterTrait;
use Augias\ElectronicInvoicingBundle\Command\CheckElectronicInvoiceAccountsCommand;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Console\IO;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\Constraint\CommandIsSuccessful;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function json_encode;
use function rewind;
use function stream_get_contents;

/**
 * Electronic invoicing is on only while the platform recognises the account:
 * the hourly check switches it off when the platform stops, and back on when
 * it verifies — and leaves it alone when the platform cannot be reached.
 */
#[Group('functional')]
#[CoversClass(CheckElectronicInvoiceAccountsCommand::class)]
#[CoversClass(ElectronicInvoiceAccountMonitor::class)]
#[CoversClass(ElectronicInvoiceProviderSettingRepository::class)]
final class CheckElectronicInvoiceAccountsCommandTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use ConsoleTesterTrait;

    public function testAnAccountUnderReviewStopsElectronicInvoicing(): void
    {
        $setting = $this->superPdp();
        $this->platformSays('needs_review');

        $this->runCheck();

        self::assertSame(AccountVerification::NeedsReview, $this->reload($setting)->getAccountVerification());
        self::assertFalse(self::getContainer()->get(ElectronicInvoiceProviderRegistry::class)->hasActiveProvider());
    }

    /**
     * Nobody has to come back and save the settings: the account the platform
     * has just verified starts working on its own.
     */
    public function testAVerifiedAccountStartsAgainOnItsOwn(): void
    {
        $setting = $this->superPdp(AccountVerification::NeedsReview);
        self::assertFalse(self::getContainer()->get(ElectronicInvoiceProviderRegistry::class)->hasActiveProvider());

        $this->platformSays('verified', company: true);
        $this->runCheck();

        self::assertSame(AccountVerification::Verified, $this->reload($setting)->getAccountVerification());
        self::assertTrue(self::getContainer()->get(ElectronicInvoiceProviderRegistry::class)->hasActiveProvider());
    }

    /**
     * An outage says nothing about the account: what the platform last said
     * stands, and invoices keep going through.
     */
    public function testAnUnreachablePlatformChangesNothing(): void
    {
        $setting = $this->superPdp(AccountVerification::Verified);

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse('', ['http_code' => 503]),
        ]));
        $this->runCheck();

        self::assertSame(AccountVerification::Verified, $this->reload($setting)->getAccountVerification());
        self::assertTrue(self::getContainer()->get(ElectronicInvoiceProviderRegistry::class)->hasActiveProvider());
    }

    /**
     * A setting from before the question existed keeps working until the
     * platform has been asked.
     */
    public function testASettingNeverCheckedStillCounts(): void
    {
        $this->superPdp();

        self::assertTrue(self::getContainer()->get(ElectronicInvoiceProviderRegistry::class)->hasActiveProvider());
    }

    private function superPdp(?AccountVerification $verification = null): ElectronicInvoiceProviderSetting
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
            ->setActive(true);

        if (null !== $verification) {
            $setting->recordAccountCheck($verification, new DateTimeImmutable('2026-09-25 09:00'));
        }

        $entityManager->persist($setting);
        $entityManager->flush();

        return $setting;
    }

    private function platformSays(string $verification, bool $company = false): void
    {
        $responses = [
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['created_at' => '2026-09-25T09:58:15Z', 'company_verification_status' => $verification])),
        ];

        if ($company) {
            $responses[] = static fn (): MockResponse => new MockResponse((string) json_encode([
                'env' => 'sandbox',
                'number' => '000000002',
                'formal_name' => 'Burger Queen',
                'vat_regime' => 'quarterly',
                'has_vat_on_debits' => false,
            ]));
        }

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient($responses));
    }

    private function reload(ElectronicInvoiceProviderSetting $setting): ElectronicInvoiceProviderSetting
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();

        $reloaded = $entityManager->find(ElectronicInvoiceProviderSetting::class, $setting->getId());
        self::assertInstanceOf(ElectronicInvoiceProviderSetting::class, $reloaded);

        return $reloaded;
    }

    private function runCheck(): string
    {
        // The booted kernel, so that the MockHttpClient set above is the one used.
        $application = new Application(self::$kernel);

        /** @var LazyCommand $lazyCommand */
        $lazyCommand = $application->find('augias:einvoicing:check-accounts');

        /** @var CheckElectronicInvoiceAccountsCommand $command */
        $command = $lazyCommand->getCommand();
        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));

        $command->setIo(new IO($this->input, $this->output));

        Assert::assertThat($command->run($this->input, $this->output), new CommandIsSuccessful());

        rewind($this->output->getStream());

        return (string) stream_get_contents($this->output->getStream());
    }
}
