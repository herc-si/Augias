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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Test\Traits\ConsoleTesterTrait;
use Augias\ElectronicInvoicingBundle\Command\PollSuperPdpInvoiceStatusCommand;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceDisputedNotification;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceRejectedNotification;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\NotificationBundle\Notification\NotificationManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
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
use function str_replace;
use function stream_get_contents;

#[Group('functional')]
#[CoversClass(PollSuperPdpInvoiceStatusCommand::class)]
final class PollSuperPdpInvoiceStatusCommandTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use ConsoleTesterTrait;
    use MockeryPHPUnitIntegration;

    public function testCommandRefreshesTheStatusOfAPendingSubmission(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
            ->setActive(true);
        $entityManager->persist($setting);

        $client = ClientFactory::createOne(['company' => $this->company]);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client]);

        $submission = new ElectronicInvoiceSubmission();
        $submission->setCompany($this->company)
            ->setInvoice($invoice)
            ->setProvider('super_pdp')
            ->setSuccess(true)
            ->setExternalReference('4242');
        $entityManager->persist($submission);
        $entityManager->flush();

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'id' => 4242,
                'events' => [
                    ['status_code' => 'api:uploaded', 'created_at' => '2026-01-01T10:00:00Z'],
                    ['status_code' => 'fr:205', 'created_at' => '2026-01-02T10:00:00Z'],
                ],
            ])),
        ]));

        $output = $this->runTestCommand();

        self::assertStringContainsString('Refreshed 1 submission(s). Errors: 0', $output);

        $entityManager->clear();
        $repository = self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceSubmission::class);
        $refreshed = $repository->find($submission->getId());

        self::assertSame('fr:205', $refreshed?->getStatusCode());
    }

    public function testCommandNotifiesUsersWhenASubmissionIsRejected(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
            ->setActive(true);
        $entityManager->persist($setting);

        $client = ClientFactory::createOne(['company' => $this->company]);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client]);

        $submission = new ElectronicInvoiceSubmission();
        $submission->setCompany($this->company)
            ->setInvoice($invoice)
            ->setProvider('super_pdp')
            ->setSuccess(true)
            ->setExternalReference('4242');
        $entityManager->persist($submission);
        $entityManager->flush();

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'id' => 4242,
                'events' => [
                    ['status_code' => 'fr:213', 'created_at' => '2026-01-02T10:00:00Z'],
                ],
            ])),
        ]));

        // The kernel is booted once per class (see EnsureApplicationInstalled), so by
        // the time this test runs, the container's own NotificationManager has already
        // been eagerly initialized during install — too late to self::getContainer()->set()
        // it. Build the command by hand instead, supplying a mocked NotificationManager
        // directly, the way WorkFlowSubscriberTest does for the same reason.
        $notificationManager = M::mock(NotificationManager::class);
        $notificationManager->shouldReceive('sendNotification')
            ->once()
            ->with(M::type(ElectronicInvoiceRejectedNotification::class));

        $command = new PollSuperPdpInvoiceStatusCommand(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class),
            self::getContainer()->get(ElectronicInvoiceProviderSettingRepository::class),
            self::getContainer()->get(SuperPdpClient::class),
            $notificationManager,
            new NullLogger(),
        );

        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));
        $command->setIo(new IO($this->input, $this->output));

        $this->statusCode = $command->run($this->input, $this->output);

        Assert::assertThat($this->statusCode, new CommandIsSuccessful());

        $entityManager->clear();
        $repository = self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceSubmission::class);
        $refreshed = $repository->find($submission->getId());

        self::assertSame('fr:213', $refreshed?->getStatusCode());
    }

    /**
     * A client's dispute reaches the company with what they dispute and why,
     * in their own words — the status alone would not say what to settle.
     */
    public function testCommandNotifiesUsersWhenAClientDisputesAnInvoice(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
            ->setActive(true);
        $entityManager->persist($setting);

        $client = ClientFactory::createOne(['company' => $this->company]);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client]);

        $submission = new ElectronicInvoiceSubmission();
        $submission->setCompany($this->company)
            ->setInvoice($invoice)
            ->setProvider('super_pdp')
            ->setSuccess(true)
            ->setExternalReference('749192');
        $entityManager->persist($submission);
        $entityManager->flush();

        // As the sandbox returned it on 25/09/2026.
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'id' => 749192,
                'events' => [
                    ['id' => 2634600, 'status_code' => 'fr:202', 'details' => [[]]],
                    ['id' => 2634658, 'status_code' => 'fr:207', 'details' => [[
                        'reason' => 'QTE_ERR',
                        'notes' => [['content_code' => '', 'contents' => [['content' => '8 cartons livrés sur 10']]]],
                    ]]],
                ],
            ])),
        ]));

        $notificationManager = M::mock(NotificationManager::class);
        $notificationManager->shouldReceive('sendNotification')
            ->once()
            ->with(M::on(static fn (mixed $notification): bool => $notification instanceof ElectronicInvoiceDisputedNotification
                && 'einvoicing.response_reason.QTE_ERR' === $notification->getParameters()['reason']
                && '8 cartons livrés sur 10' === $notification->getParameters()['note']));

        $command = new PollSuperPdpInvoiceStatusCommand(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class),
            self::getContainer()->get(ElectronicInvoiceProviderSettingRepository::class),
            self::getContainer()->get(SuperPdpClient::class),
            $notificationManager,
            new NullLogger(),
        );

        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));
        $command->setIo(new IO($this->input, $this->output));

        Assert::assertThat($command->run($this->input, $this->output), new CommandIsSuccessful());

        $entityManager->clear();
        $refreshed = self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceSubmission::class)->find($submission->getId());

        self::assertSame('fr:207', $refreshed?->getStatusCode());
    }

    private function runTestCommand(): string
    {
        // Reuse the already-booted kernel (from EnsureApplicationInstalled) instead of
        // calling self::bootKernel() again, which would create a fresh container and
        // discard the MockHttpClient set on the current one.
        $application = new Application(self::$kernel);

        /** @var LazyCommand $lazyCommand */
        $lazyCommand = $application->find('augias:einvoicing:poll-super-pdp-status');

        /** @var PollSuperPdpInvoiceStatusCommand $command */
        $command = $lazyCommand->getCommand();
        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));

        $command->setIo(new IO($this->input, $this->output));

        $this->statusCode = $command->run($this->input, $this->output);

        Assert::assertThat($this->statusCode, new CommandIsSuccessful());

        rewind($this->output->getStream());

        $display = stream_get_contents($this->output->getStream());

        return str_replace(\PHP_EOL, "\n", $display);
    }
}
