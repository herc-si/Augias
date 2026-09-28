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

namespace Augias\SaasBundle\Tests\EventSubscriber;

use Augias\CoreBundle\Listener\EmailFromListener;
use Augias\MailerBundle\Factory\MailerConfigFactory;
use Augias\SaasBundle\EventSubscriber\PlatformSenderListener;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[CoversClass(PlatformSenderListener::class)]
final class PlatformSenderListenerTest extends TestCase
{
    private const string PLATFORM = 'augias@herc-si.fr';

    public function testTheCompanyAddressMovesToReplyTo(): void
    {
        $message = new Email()->from(new Address('contact@acme.fr', 'Acme SARL'))->to('client@example.org');
        $envelope = $this->send($message);

        self::assertEquals([new Address(self::PLATFORM, 'Acme SARL')], $message->getFrom());
        self::assertEquals([new Address('contact@acme.fr', 'Acme SARL')], $message->getReplyTo());
        self::assertSame(self::PLATFORM, $envelope->getSender()->getAddress());
    }

    public function testAReplyToAlreadySetIsKept(): void
    {
        $message = new Email()->from('contact@acme.fr')->replyTo('compta@acme.fr')->to('client@example.org');
        $this->send($message);

        self::assertEquals([new Address('compta@acme.fr')], $message->getReplyTo());
        self::assertSame(self::PLATFORM, $message->getFrom()[0]->getAddress());
    }

    public function testNoReplyToThePlaceholderOrThePlatform(): void
    {
        foreach (['no-reply@augias.example', self::PLATFORM] as $address) {
            $message = new Email()->from($address)->to('client@example.org');
            $this->send($message);

            self::assertSame([], $message->getReplyTo(), $address);
            self::assertSame(self::PLATFORM, $message->getFrom()[0]->getAddress());
        }
    }

    public function testAMessageWithoutFromStillLeavesFromThePlatform(): void
    {
        $message = new Email()->to('client@example.org')->text('x');
        $envelope = new Envelope(new Address('no-reply@localhost'), [new Address('client@example.org')]);
        $this->listener(null)(new MessageEvent($message, $envelope, 'smtp'));

        self::assertEquals([new Address(self::PLATFORM)], $message->getFrom());
        self::assertSame([], $message->getReplyTo());
    }

    public function testACompanyWithItsOwnSendingServiceKeepsItsAddress(): void
    {
        $message = new Email()->from('contact@acme.fr')->to('client@example.org');
        $envelope = $this->send($message, '{"provider": "Amazon SES", "config": {}}');

        self::assertEquals([new Address('contact@acme.fr')], $message->getFrom());
        self::assertSame([], $message->getReplyTo());
        self::assertSame('contact@acme.fr', $envelope->getSender()->getAddress());
    }

    public function testItRunsAfterTheListenerThatSetsTheCompanyAddress(): void
    {
        self::assertLessThan(
            EmailFromListener::getSubscribedEvents()[MessageEvent::class][1],
            PlatformSenderListener::getSubscribedEvents()[MessageEvent::class][1],
        );
    }

    private function send(Email $message, ?string $provider = null): Envelope
    {
        $envelope = Envelope::create($message);
        $this->listener($provider)(new MessageEvent($message, $envelope, 'smtp'));

        return $envelope;
    }

    private function listener(?string $provider): PlatformSenderListener
    {
        $config = $this->createStub(SystemConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): ?string => $key === MailerConfigFactory::CONFIG_KEY ? $provider : null,
        );

        return new PlatformSenderListener($config, self::PLATFORM);
    }
}
