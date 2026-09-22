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

namespace Augias\CoreBundle\Tests\Command;

use Augias\CoreBundle\AccessJournalRetention;
use Augias\CoreBundle\Command\PurgeAccessJournalCommand;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\RecordAccess;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\ConsoleTesterTrait;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\User;
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
use Symfony\Component\Uid\Ulid;
use function array_map;
use function sort;
use function sprintf;

/**
 * The journal forgets, and this is what makes the page's promise true.
 *
 * @see \Augias\CoreBundle\AccessJournalRetention
 */
#[CoversClass(PurgeAccessJournalCommand::class)]
final class PurgeAccessJournalCommandTest extends KernelTestCase
{
    use ConsoleTesterTrait;
    use DoctrineTestTrait;

    public function testItForgetsWhatIsPastTheRetentionPeriodAndKeepsTheRest(): void
    {
        $company = CompanyFactory::createOne();
        $user = UserFactory::createOne(['email' => 'keeper@journal.test', 'companies' => [$company]]);

        $this->record($company, $user, 'yesterday', '-1 day');
        $this->record($company, $user, 'just-inside', sprintf('-%d days', AccessJournalRetention::DAYS - 1));
        $this->record($company, $user, 'just-outside', sprintf('-%d days', AccessJournalRetention::DAYS + 1));
        $this->record($company, $user, 'ancient', '-2 years');

        $this->runPurge();

        $this->em->clear();

        $remaining = array_map(
            static fn (RecordAccess $record): string => $record->getLabel(),
            $this->em->getRepository(RecordAccess::class)->findBy(['user' => $user->getId()]),
        );

        sort($remaining);

        self::assertSame(['just-inside', 'yesterday'], $remaining);
    }

    private function record(Company $company, User $user, string $label, string $age): void
    {
        $this->em->persist(
            new RecordAccess(
                $company,
                $user,
                RecordKind::Invoice,
                new Ulid(),
                $label,
                new DateTimeImmutable()->modify($age),
            ),
        );

        $this->em->flush();
    }

    private function runPurge(): void
    {
        $application = new Application(self::bootKernel());

        /** @var LazyCommand $lazyCommand */
        $lazyCommand = $application->find('augias:core:purge-access-journal');

        /** @var PurgeAccessJournalCommand $command */
        $command = $lazyCommand->getCommand();

        $this->initOutput([]);
        $this->input = new ArrayInput([]);
        $this->input->setStream(self::createStream([]));

        $command->setIo(new IO($this->input, $this->output));

        Assert::assertThat($command->run($this->input, $this->output), new CommandIsSuccessful());
    }
}
