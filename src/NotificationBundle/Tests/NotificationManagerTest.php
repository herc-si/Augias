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

namespace Augias\NotificationBundle\Tests;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\FakerTestTrait;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Configurator\ConfiguratorInterface;
use Augias\NotificationBundle\Configurator\TelegramConfigurator;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Entity\UserNotification;
use Augias\NotificationBundle\Exception\InvalidNotificationMessageException;
use Augias\NotificationBundle\Notification\NotificationManager;
use Augias\NotificationBundle\Notification\NotificationMessage;
use Augias\NotificationBundle\Test\Factory\UserNotificationFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Hamcrest\Core\IsEqual;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\Transport\Dsn;
use Twig\Environment;

#[CoversClass(NotificationManager::class)]
final class NotificationManagerTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use FakerTestTrait;
    use MockeryPHPUnitIntegration;

    private NotificationManager $notificationManager;

    private NotifierInterface | M\MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifier = M::mock(NotifierInterface::class);

        $this->notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator([]),
            new NullLogger(),
            new RequestStack(),
        );
    }

    public function testMessageWithoutAttribute(): void
    {
        $class = new class() extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $this->expectException(InvalidNotificationMessageException::class);
        $this->expectExceptionMessageIsOrContains('The notification message "' . $class::class . '" must have the ' . AsNotification::class . ' set.');

        $this->notificationManager->sendNotification($class);
    }

    public function testSendEmailNotification(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = new User()
            ->setEmail($email)
            ->setPassword('password');

        $userNotification = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(true)
            ->setUser($user);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($userNotification);
        $em->persist($user);
        $em->flush();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once();

        $this->notificationManager->sendNotification($class);
        self::assertSame(['email'], $class->getChannels(new Recipient($email, '')));
    }

    public function testSendNotificationWithNoUsers(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $this->notifier
            ->expects('send')
            ->never();

        $this->notificationManager->sendNotification($class);
        self::assertSame([], $class->getChannels(new Recipient($email, '')));
    }

    public function testSendWithNoTransports(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = new User()
            ->setEmail($email)
            ->setPassword('password');

        $userNotification = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(false)
            ->setUser($user);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($userNotification);
        $em->persist($user);
        $em->flush();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once();

        $this->notificationManager->sendNotification($class);
        self::assertSame([], $class->getChannels(new Recipient($email, '')));
    }

    public function testSendTransportNotification(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = new User()
            ->setEmail($email)
            ->setPassword('password');

        $transportSetting = new TransportSetting()
            ->setName('Test Foo')
            ->setTransport('FooBar')
            ->setUser($user);

        $userNotification = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(false)
            ->setUser($user)
            ->addTransport($transportSetting);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($user);
        $em->persist($transportSetting);
        $em->persist($userNotification);
        $em->flush();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once();

        $configurator = M::mock(ConfiguratorInterface::class);
        $configurator
            ->expects('getType')
            ->once()
            ->andReturn('chatter');

        $notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator(['FooBar' => static fn () => $configurator]),
            new NullLogger(),
            new RequestStack(),
        );

        $notificationManager->sendNotification($class);
        self::assertSame(['chat/' . $transportSetting->getId()->toString()], $class->getChannels(new Recipient($email, '')));
    }

    public function testSendTransportNotificationWithMultipleUsers(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email1 = $this->getFaker()->email();
        $email2 = $this->getFaker()->email();

        $user1 = new User()
            ->setEmail($email1)
            ->setPassword('password');
        $user2 = new User()
            ->setEmail($email2)
            ->setPassword('password');

        $transportSetting1 = new TransportSetting()
            ->setName('Test Foo')
            ->setTransport('FooBar')
            ->setUser($user1);
        $transportSetting2 = new TransportSetting()
            ->setName('Test Foo')
            ->setTransport('FooBar')
            ->setUser($user2);

        $userNotification1 = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(false)
            ->setUser($user1)
            ->addTransport($transportSetting1);
        $userNotification2 = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(false)
            ->setUser($user2)
            ->addTransport($transportSetting2);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($user1);
        $em->persist($user2);
        $em->persist($transportSetting1);
        $em->persist($transportSetting2);
        $em->persist($userNotification1);
        $em->persist($userNotification2);
        $em->flush();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email1, '')))
            ->once();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email2, '')))
            ->once();

        $configurator = M::mock(ConfiguratorInterface::class);
        $configurator
            ->expects('getType')
            ->twice()
            ->andReturn('chatter');

        $notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator(['FooBar' => static fn () => $configurator]),
            new NullLogger(),
            new RequestStack(),
        );

        $notificationManager->sendNotification($class);
        self::assertSame(['chat/' . $transportSetting2->getId()->toString()], $class->getChannels(new Recipient($email2, '')));
    }

    public function testSendMultipleTransportNotification(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = new User()
            ->setEmail($email)
            ->setPassword('password');

        $transportSetting = new TransportSetting()
            ->setName('Test Foo')
            ->setTransport('FooBar')
            ->setUser($user)
        ;

        $transportSetting2 = new TransportSetting()
            ->setName('Test Foos')
            ->setTransport('FooBars')
            ->setUser($user)
        ;

        $userNotification = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(true)
            ->setUser($user)
            ->addTransport($transportSetting)
            ->addTransport($transportSetting2)
        ;

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($user);
        $em->persist($transportSetting);
        $em->persist($transportSetting2);
        $em->persist($userNotification);
        $em->flush();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once();

        $configurator = new class() implements ConfiguratorInterface {
            public static function getName(): string
            {
                return 'Test Foo';
            }

            public static function getType(): string
            {
                return 'chatter';
            }

            public function getForm(): string
            {
                return '';
            }

            public function configure(array $config): Dsn
            {
                return new Dsn('');
            }
        };

        $configurator2 = new class() implements ConfiguratorInterface {
            public static function getName(): string
            {
                return 'Test Foo';
            }

            public static function getType(): string
            {
                return 'texter';
            }

            public function getForm(): string
            {
                return '';
            }

            public function configure(array $config): Dsn
            {
                return new Dsn('');
            }
        };

        $notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator(['FooBar' => static fn () => $configurator, 'FooBars' => static fn () => $configurator2]),
            new NullLogger(),
            new RequestStack(),
        );

        $notificationManager->sendNotification($class);
        self::assertSame(
            [
                'email',
                'chat/' . $transportSetting->getId()->toString(),
                'sms/' . $transportSetting2->getId()->toString(),
            ],
            $class->getChannels(
                new Recipient($email, '')
            )
        );
    }

    public function testSendNotificationLogsAndFlagsFailureWhenTransportThrows(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = UserFactory::createOne([
            'email' => $email,
            'password' => 'password',
            'companies' => [$this->company],
        ]);

        $userNotification = UserNotificationFactory::createOne([
            'event' => 'test_event',
            'email' => true,
            'user' => $user,
            'company' => $this->company,
        ]);

        $transportException = new class('Boom') extends RuntimeException implements TransportExceptionInterface {
            public function getDebug(): string
            {
                return '';
            }
        };

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once()
            ->andThrow($transportException);

        $logger = M::mock(LoggerInterface::class);
        $logger
            ->expects('error')
            ->once()
            ->with(
                'Failed to send notification: Boom',
                IsEqual::equalTo(['exception' => $transportException, 'event' => 'test_event']),
            );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);

        $requestStack = new RequestStack([$request]);

        $notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator([]),
            $logger,
            $requestStack,
        );

        $notificationManager->sendNotification($class);

        self::assertSame(['notification.send_failed'], $session->getFlashBag()->get('error'));
    }

    public function testAChatRefusalWrappedByMessengerIsCaughtToo(): void
    {
        $class = new #[AsNotification(name: 'test_event')] class extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $email = $this->getFaker()->email();

        $user = UserFactory::createOne([
            'email' => $email,
            'password' => 'password',
            'companies' => [$this->company],
        ]);

        $telegram = new TransportSetting();
        $telegram->setName('Perso');
        $telegram->setTransport('Telegram');
        $telegram->setSettings(['token' => '1:a', 'chat_id' => '42']);
        $telegram->setUser($user);
        $telegram->setCompany($this->company);
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist($telegram);
        $em->flush();

        UserNotificationFactory::createOne([
            'event' => 'test_event',
            'email' => false,
            'user' => $user,
            'company' => $this->company,
            'transports' => [$telegram],
        ]);

        // What the test instance raised on creating a client (29/09/2026):
        // Telegram's "chat not found", wrapped by Messenger since the chat
        // message is handled in the request.
        $transportException = new HandlerFailedException(
            new Envelope(new ChatMessage('x')->transport($telegram->getId()->toString())),
            [new class('Bad Request: chat not found') extends RuntimeException implements TransportExceptionInterface {
                public function getDebug(): string
                {
                    return '';
                }
            }],
        );

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($email, '')))
            ->once()
            ->andThrow($transportException);

        $logger = M::mock(LoggerInterface::class);
        $logger
            ->expects('error')
            ->once()
            ->with(
                'Failed to send notification: ' . $transportException->getMessage(),
                IsEqual::equalTo(['exception' => $transportException, 'event' => 'test_event']),
            );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);

        $requestStack = new RequestStack([$request]);

        $notificationManager = new NotificationManager(
            $this->notifier,
            self::getContainer()->get('doctrine')->getRepository(UserNotification::class),
            new ServiceLocator(['Telegram' => static fn (): TelegramConfigurator => new TelegramConfigurator()]),
            $logger,
            $requestStack,
            self::getContainer()->get('translator'),
        );

        $notificationManager->sendNotification($class);

        // Which integration, and Telegram's own words — not "check your email settings".
        $flash = $session->getFlashBag()->get('error');
        self::assertCount(1, $flash);
        self::assertStringContainsString('Telegram "Perso"', $flash[0]);
        self::assertStringContainsString('chat not found', $flash[0]);
    }

    /**
     * Run from cron, the company filter is off: the overdue invoice of one
     * company was told to the users of every company who had asked to hear
     * about it (found 06/10/2026).
     */
    public function testFromCronOnlyTheCompanyTheNotificationIsAboutIsTold(): void
    {
        $other = CompanyFactory::createOne(['name' => 'Other']);
        $ours = $this->subscribe($this->company);
        $theirs = $this->subscribe($other);

        $about = new class($this->company) {
            public function __construct(
                private readonly Company $company
            ) {
            }

            public function getCompany(): Company
            {
                return $this->company;
            }
        };

        $class = new #[AsNotification(name: 'test_event')] class(['invoice' => $about]) extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($ours, '')))
            ->once();

        $this->notifier
            ->expects('send')
            ->with($class, IsEqual::equalTo(new Recipient($theirs, '')))
            ->never();

        $this->withoutCompanyFilter(fn () => $this->notificationManager->sendNotification($class));
    }

    public function testFromCronANotificationAboutNoCompanyIsNotSent(): void
    {
        $this->subscribe($this->company);
        $this->subscribe(CompanyFactory::createOne(['name' => 'Other']));

        $class = new #[AsNotification(name: 'test_event')] class() extends NotificationMessage {
            public function getTextContent(Environment $twig): string
            {
                return '';
            }
        };

        $this->notifier
            ->expects('send')
            ->never();

        $this->withoutCompanyFilter(fn () => $this->notificationManager->sendNotification($class));
    }

    private function subscribe(Company $company): string
    {
        $email = $this->getFaker()->unique()->email();

        $user = new User()
            ->setEmail($email)
            ->setPassword('password');
        $user->addCompany($company);

        $userNotification = new UserNotification()
            ->setEvent('test_event')
            ->setEmail(true)
            ->setUser($user);
        $userNotification->setCompany($company);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($user);
        $em->persist($userNotification);
        $em->flush();

        return $email;
    }

    private function withoutCompanyFilter(callable $send): void
    {
        $filters = self::getContainer()->get('doctrine.orm.entity_manager')->getFilters();
        $filters->disable('company');

        try {
            $send();
        } finally {
            $filters->enable('company');
        }
    }
}
