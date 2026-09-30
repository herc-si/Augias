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

namespace Augias\NotificationBundle\Tests\Configurator;

use Augias\NotificationBundle\Configurator\TelegramConfigurator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransportFactory;
use Symfony\Component\Notifier\Exception\IncompleteDsnException;

#[CoversClass(TelegramConfigurator::class)]
final class TelegramConfiguratorTest extends TestCase
{
    /**
     * A real bot token has a colon, and Symfony reads it as the DSN's user and
     * password. Encoded whole, it was refused as "Malformed token" every time
     * (test instance, 29/09/2026).
     */
    public function testARealBotTokenMakesATransportSymfonyAccepts(): void
    {
        $dsn = new TelegramConfigurator()->configure(['token' => ' 1234567890:AAH-abc_DEF123 ', 'chat_id' => '-1001234567890']);

        $transport = new TelegramTransportFactory()->create($dsn);

        self::assertSame('telegram://api.telegram.org?channel=-1001234567890', (string) $transport);
    }

    public function testATokenWithoutItsSecretIsStillRefusedWithTheReason(): void
    {
        $this->expectException(IncompleteDsnException::class);
        $this->expectExceptionMessage('Malformed token');

        new TelegramTransportFactory()->create(new TelegramConfigurator()->configure(['token' => '1234567890', 'chat_id' => '42']));
    }
}
