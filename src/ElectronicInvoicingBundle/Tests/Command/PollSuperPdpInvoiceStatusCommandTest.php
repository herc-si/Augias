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
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmissionEvent;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingAlerts;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceDisputedNotification;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceRejectedNotification;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoicingProblemNotification;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpAccessTokens;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\Constraint\CommandIsSuccessful;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function array_map;
use function json_encode;
use function rewind;
use function str_ends_with;
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
            self::getContainer()->get(SuperPdpAccessTokens::class),
            $notificationManager,
            new NullLogger(),
            new ElectronicInvoicingAlerts($notificationManager, new NullLogger()),
            new MockClock('2026-10-06 12:00:00 UTC'),
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
            self::getContainer()->get(SuperPdpAccessTokens::class),
            $notificationManager,
            new NullLogger(),
            new ElectronicInvoicingAlerts($notificationManager, new NullLogger()),
            new MockClock('2026-10-06 12:00:00 UTC'),
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

    /**
     * Every step the platform dated is kept, once — the invoice page shows
     * what happened and when, not only where it ended up.
     */
    public function testEveryStepIsKeptOnceAsTheInvoiceHistory(): void
    {
        $submission = $this->submission('5150');
        $invoice = static fn (): MockResponse => new MockResponse((string) json_encode([
            'id' => 5150,
            'events' => [
                ['id' => 3, 'status_code' => 'fr:203', 'created_at' => '2026-10-06T09:02:00Z'],
                ['id' => 1, 'status_code' => 'api:uploaded', 'created_at' => '2026-10-06T09:00:00Z'],
                ['id' => 2, 'status_code' => 'fr:200', 'created_at' => '2026-10-06T09:01:00Z'],
            ],
        ]));
        $token = static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600]));
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([$token, $invoice, $token, $invoice]));

        $notificationManager = M::spy(NotificationManager::class);
        $this->poll($notificationManager);
        $this->poll($notificationManager);

        $events = self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceSubmissionEvent::class)->findBy(['submission' => $submission], ['occurredAt' => 'ASC']);

        self::assertSame(['api:uploaded', 'fr:200', 'fr:203'], array_map(static fn (ElectronicInvoiceSubmissionEvent $event): string => $event->getStatusCode(), $events));
        self::assertSame('2026-10-06 09:00', $events[0]->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        $notificationManager->shouldNotHaveReceived('sendNotification');
    }

    /**
     * Suspended by the client's platform, the invoice waits for something:
     * the company is told what, in the client's words.
     */
    public function testASuspendedInvoiceIsToldWithWhatIsMissing(): void
    {
        $this->submission('6160');

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'id' => 6160,
                'events' => [
                    ['id' => 10, 'status_code' => 'fr:203', 'created_at' => '2026-10-06T09:00:00Z'],
                    ['id' => 11, 'status_code' => 'fr:208', 'created_at' => '2026-10-06T10:00:00Z', 'details' => [[
                        'notes' => [['contents' => [['content' => 'Numéro de commande manquant']]]],
                    ]]],
                ],
            ])),
        ]));

        $notificationManager = M::mock(NotificationManager::class);
        $notificationManager->shouldReceive('sendNotification')
            ->once()
            ->with(M::on(fn (mixed $notification): bool => $notification instanceof ElectronicInvoicingProblemNotification
                && ElectronicInvoicingProblem::Suspended === $notification->getParameters()['problem']
                && 'Numéro de commande manquant' === $notification->getParameters()['detail']
                && $this->company->getId()->equals($notification->getParameters()['company']->getId())));

        $this->poll($notificationManager);
    }

    /**
     * Accepted, an invoice is still followed so that its payment shows —
     * for a while: one paid outside the platform would be asked about forever.
     */
    public function testAnAcceptedInvoiceIsFollowedUntilPaidButNotForever(): void
    {
        $this->submission('7001', 'fr:205');
        $this->submission('7002', 'fr:205', '2026-06-01 10:00:00');
        $this->submission('7003', 'fr:212');

        // One invoice asked about, and one only: the two others are not followed any more.
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/token')
            ? new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600]))
            : new MockResponse((string) json_encode([
                'id' => 7001,
                'events' => [['id' => 20, 'status_code' => 'fr:212', 'created_at' => '2026-10-06T09:00:00Z']],
            ])));
        self::getContainer()->set(HttpClientInterface::class, $http);

        $this->poll(M::spy(NotificationManager::class));

        $repository = self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceSubmission::class);
        self::assertSame('fr:212', $repository->findOneBy(['externalReference' => '7001'])?->getStatusCode());
        self::assertSame('fr:205', $repository->findOneBy(['externalReference' => '7002'])?->getStatusCode());
        self::assertSame(2, $http->getRequestsCount());
    }

    private function submission(string $reference, ?string $statusCode = null, ?string $created = null): ElectronicInvoiceSubmission
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        if (null === self::getContainer()->get(ElectronicInvoiceProviderSettingRepository::class)->findOneBy(['provider' => 'super_pdp'])) {
            $setting = new ElectronicInvoiceProviderSetting();
            $setting->setCompany($this->company)
                ->setName('SUPER PDP')
                ->setProvider('super_pdp')
                ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
                ->setActive(true);
            $entityManager->persist($setting);
        }

        $client = ClientFactory::createOne(['company' => $this->company]);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client]);

        $submission = new ElectronicInvoiceSubmission();
        $submission->setCompany($this->company)
            ->setInvoice($invoice)
            ->setProvider('super_pdp')
            ->setSuccess(true)
            ->setStatusCode($statusCode)
            ->setExternalReference($reference);
        $entityManager->persist($submission);
        $entityManager->flush();

        if (null !== $created) {
            $entityManager->getConnection()->executeStatement(
                'UPDATE ' . ElectronicInvoiceSubmission::TABLE_NAME . ' SET created = :created WHERE external_reference = :reference',
                ['created' => $created, 'reference' => $reference],
            );
        }

        return $submission;
    }

    private function poll(NotificationManager $notificationManager): void
    {
        $command = new PollSuperPdpInvoiceStatusCommand(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class),
            self::getContainer()->get(ElectronicInvoiceProviderSettingRepository::class),
            self::getContainer()->get(SuperPdpClient::class),
            self::getContainer()->get(SuperPdpAccessTokens::class),
            $notificationManager,
            new NullLogger(),
            new ElectronicInvoicingAlerts($notificationManager, new NullLogger()),
            new MockClock('2026-10-06 12:00:00 UTC'),
        );

        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));
        $command->setIo(new IO($this->input, $this->output));

        Assert::assertThat($command->run($this->input, $this->output), new CommandIsSuccessful());

        self::getContainer()->get('doctrine')->getManager()->clear();
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
