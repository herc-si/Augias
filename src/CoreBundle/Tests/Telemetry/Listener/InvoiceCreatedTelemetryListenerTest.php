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

namespace Augias\CoreBundle\Tests\Telemetry\Listener;

use Augias\CoreBundle\ConfigWriter;
use Augias\CoreBundle\Telemetry\Listener\InvoiceCreatedTelemetryListener;
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use Augias\InvoiceBundle\Event\InvoiceEvent;
use Augias\InvoiceBundle\Event\InvoiceEvents;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(InvoiceCreatedTelemetryListener::class)]
final class InvoiceCreatedTelemetryListenerTest extends TestCase
{
    public function testItDispatchesInvoiceCreatedEventOnPostCreate(): void
    {
        $bus = new CollectingMessageBus();

        $telemetry = new Telemetry(
            $bus,
            $this->createConfigWriter(),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            'build-123',
            true,
            'https://collector.example.com',
            'manual',
            false,
            'en',
            null,
        );

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(
            InvoiceEvents::INVOICE_POST_CREATE,
            new InvoiceCreatedTelemetryListener($telemetry),
        );

        $eventDispatcher->dispatch(new InvoiceEvent(), InvoiceEvents::INVOICE_POST_CREATE);

        self::assertCount(1, $bus->messages);
        self::assertSame('event', $bus->messages[0]->type);
        self::assertSame('invoice_created', $bus->messages[0]->payload['event']);
        self::assertSame('augias', $bus->messages[0]->payload['app']);
        self::assertSame('build-123', $bus->messages[0]->payload['build_id']);
    }

    private function createConfigWriter(): ConfigWriter
    {
        $vault = $this->createMock(AbstractVault::class);
        $vault->expects(self::never())->method('generateKeys')->willReturn(true);

        return new ConfigWriter($vault, '/tmp/augias-test-config');
    }
}
