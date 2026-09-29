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

namespace Augias\NotificationBundle\Tests\Factory;

use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Factory\NotificationTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Notifier\Message\ChatMessage;

#[CoversClass(NotificationTransportFactory::class)]
final class NotificationTransportFactoryTest extends LiveComponentTest
{
    /**
     * Every notification builds every integration's transport. One set up
     * wrong threw from there, and creating a client was a 500 (test instance,
     * 29/09/2026). It is left out; the others still go.
     */
    public function testAnIntegrationThatCannotBeBuiltIsLeftOutNotFatal(): void
    {
        $user = $this->getUser();
        $em = self::getContainer()->get('doctrine')->getManager();

        foreach ([['Broken', 'Telegram', ['token' => 'no-secret-here', 'chat_id' => '42']], ['Working', 'FakeChat', ['to' => 'chat@example.org', 'from' => 'augias@example.org']]] as [$name, $transport, $settings]) {
            $setting = new TransportSetting();
            $setting->setName($name);
            $setting->setTransport($transport);
            $setting->setSettings($settings);
            $setting->setUser($user);
            $setting->setCompany($user->getCompanies()->first());
            $em->persist($setting);
        }

        $em->flush();

        $factory = self::getContainer()->get('chatter.transport_factory');
        self::assertInstanceOf(NotificationTransportFactory::class, $factory);

        $transports = $factory->fromStrings([]);

        self::assertTrue($transports->supports(new ChatMessage('x')));
    }
}
