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

namespace Augias\ElectronicInvoicingBundle\Tests\Manager;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicDeclaration;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicDeclarationSync;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingAlerts;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoicingProblemNotification;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicDeclarationRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\NotificationBundle\Notification\NotificationManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use function json_encode;
use function str_contains;

/**
 * The declarations SUPER PDP sent the tax administration, copied, and a
 * rejection told once.
 */
#[CoversClass(ElectronicDeclarationSync::class)]
final class ElectronicDeclarationSyncTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use MockeryPHPUnitIntegration;

    /** @var list<array<string, mixed>> */
    private array $declared = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth2/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'a-token']));
            }

            if (str_contains($url, '/ereportings')) {
                return new MockResponse((string) json_encode(['data' => $this->declared, 'has_more' => false]));
            }

            return new MockResponse('', ['http_code' => 404]);
        })));

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)->setName('SUPER PDP')->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])->setActive(true);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();
    }

    public function testCopiesTheDeclarationsWithTheirLatestStatus(): void
    {
        $this->declared = [
            $this->declaration(7, 'transaction', ['ppf:ereporting' => '2026-10-11T08:00:00Z', 'ppf:ereporting-ack' => '2026-10-11T09:00:00Z']),
            $this->declaration(8, 'payment', []),
        ];

        self::assertSame(3, $this->sync()->refresh($this->company));
        self::assertSame(0, $this->sync()->refresh($this->company), 'Nothing new the second time.');

        $declarations = self::getContainer()->get(ElectronicDeclarationRepository::class)->forCompany($this->company->getId());
        self::assertCount(2, $declarations);

        $sales = $declarations[0]->getKind() === 'transaction' ? $declarations[0] : $declarations[1];
        self::assertSame('ppf:ereporting-ack', $sales->getStatus());
        self::assertTrue($sales->isAccepted());
        self::assertSame('2026-09-21', $sales->getPeriodStart()->format('Y-m-d'));
    }

    /**
     * Checked every hour, a rejection is told the first time it is seen.
     */
    public function testARejectionIsToldOnce(): void
    {
        $notifications = M::mock(NotificationManager::class);
        $notifications->shouldReceive('sendNotification')
            ->once()
            ->with(M::on(static fn (mixed $notification): bool => $notification instanceof ElectronicInvoicingProblemNotification
                && ElectronicInvoicingProblem::DeclarationRejected === $notification->getParameters()['problem']));

        $sync = $this->sync(new ElectronicInvoicingAlerts($notifications, new NullLogger()));

        $this->declared = [$this->declaration(7, 'transaction', ['ppf:ereporting' => '2026-10-11T08:00:00Z'])];
        $sync->refresh($this->company);

        $this->declared = [$this->declaration(7, 'transaction', ['ppf:ereporting' => '2026-10-11T08:00:00Z', 'ppf:ereporting-rejected' => '2026-10-11T10:00:00Z'])];
        $sync->refresh($this->company);
        $sync->refresh($this->company);

        $declaration = self::getContainer()->get(ElectronicDeclarationRepository::class)->findOne($this->company->getId(), 'super_pdp', '7');
        self::assertInstanceOf(ElectronicDeclaration::class, $declaration);
        self::assertTrue($declaration->isRejected());
    }

    /**
     * @param array<string, string> $events status code => when
     *
     * @return array<string, mixed>
     */
    private function declaration(int $id, string $kind, array $events): array
    {
        $list = [];
        $eventId = $id * 10;

        foreach ($events as $code => $at) {
            $list[] = ['id' => ++$eventId, 'status_code' => $code, 'created_at' => $at];
        }

        return [
            'id' => $id,
            'company_id' => 92568,
            'kind' => $kind,
            'role_code' => 'SE',
            'start_period' => '2026-09-21',
            'end_period' => '2026-09-30',
            'events' => $list,
        ];
    }

    private function sync(?ElectronicInvoicingAlerts $alerts = null): ElectronicDeclarationSync
    {
        return new ElectronicDeclarationSync(
            self::getContainer()->get(ElectronicInvoiceProviderSettingRepository::class),
            self::getContainer()->get(ElectronicInvoiceProviderRegistry::class),
            self::getContainer()->get(ElectronicDeclarationRepository::class),
            self::getContainer()->get('doctrine.orm.entity_manager'),
            $alerts ?? self::getContainer()->get(ElectronicInvoicingAlerts::class),
            self::getContainer()->get(TranslatorInterface::class),
        );
    }
}
