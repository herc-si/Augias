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

use Augias\CoreBundle\Command\PurgeErrorJournalCommand;
use Augias\CoreBundle\Tests\Watch\JournalDatabase;
use Augias\CoreBundle\Watch\ErrorJournal;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Console\IO;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(PurgeErrorJournalCommand::class)]
final class PurgeErrorJournalCommandTest extends TestCase
{
    use JournalDatabase;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->journalDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->dropJournalDatabase();
    }

    public function testErrorsNotSeenForNinetyDaysAreForgottenTheOthersKept(): void
    {
        $journal = new ErrorJournal($this->db);
        $journal->recordError('old', 'ERROR', 'web', null, 'old', null, null, new DateTimeImmutable('2026-08-01 10:00:00'));
        $journal->recordError('recent', 'ERROR', 'web', null, 'recent', null, null, new DateTimeImmutable('2026-08-01 10:00:00'));
        // Seen again since: the last occurrence is what counts.
        $journal->recordError('recent', 'ERROR', 'web', null, 'recent', null, null, new DateTimeImmutable('2026-11-10 10:00:00'));

        $command = new PurgeErrorJournalCommand($this->db, new MockClock('2026-11-17 10:00:00'));
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command->setIo(new IO($input, $output));

        self::assertSame(0, $command->run($input, $output));
        self::assertSame(['recent'], $this->db->fetchFirstColumn('SELECT fingerprint FROM error_records'));
    }
}
