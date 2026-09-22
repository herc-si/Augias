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

namespace Augias\UserBundle\Tests\Command;

use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\ConsoleTesterTrait;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Command\PurgeLoginRecordsCommand;
use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\LoginOutcome;
use Augias\UserBundle\LoginRecordRetention;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use SolidWorx\Platform\PlatformBundle\Console\IO;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\Constraint\CommandIsSuccessful;
use function array_map;
use function sort;
use function sprintf;

/**
 * The journal has a short memory on purpose, and this is what makes that true.
 *
 * @see \Augias\UserBundle\LoginRecordRetention
 */
#[CoversClass(PurgeLoginRecordsCommand::class)]
final class PurgeLoginRecordsCommandTest extends KernelTestCase
{
    use ConsoleTesterTrait;
    use DoctrineTestTrait;

    public function testItForgetsWhatIsPastTheRetentionPeriodAndKeepsTheRest(): void
    {
        $user = UserFactory::createOne([
            'email' => 'kept@sign-ins.test',
            'companies' => [CompanyFactory::createOne()],
        ]);

        $this->record($user, 'yesterday', '-1 day');
        $this->record($user, 'just-inside', sprintf('-%d days', LoginRecordRetention::DAYS - 1));
        $this->record($user, 'just-outside', sprintf('-%d days', LoginRecordRetention::DAYS + 1));
        $this->record($user, 'ancient', '-2 years');

        $this->runPurge();

        $this->em->clear();

        $remaining = array_map(
            static fn (LoginRecord $record): string => $record->getIdentifier(),
            $this->em->getRepository(LoginRecord::class)->findBy(['user' => $user->getId()]),
        );

        sort($remaining);

        self::assertSame(['just-inside', 'yesterday'], $remaining);
    }

    private function record(User $user, string $identifier, string $age): void
    {
        $this->em->persist(
            new LoginRecord(
                $user,
                $identifier,
                LoginOutcome::Success,
                new DateTimeImmutable()->modify($age),
            ),
        );

        $this->em->flush();
    }

    private function runPurge(): void
    {
        $application = new Application(self::bootKernel());

        /** @var LazyCommand $lazyCommand */
        $lazyCommand = $application->find('augias:users:purge-sign-ins');

        /** @var PurgeLoginRecordsCommand $command */
        $command = $lazyCommand->getCommand();

        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));

        $command->setIo(new IO($this->input, $this->output));

        Assert::assertThat($command->run($this->input, $this->output), new CommandIsSuccessful());
    }
}
