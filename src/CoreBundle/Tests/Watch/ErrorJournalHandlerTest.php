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

namespace Augias\CoreBundle\Tests\Watch;

use Augias\CoreBundle\Watch\ErrorJournal;
use Augias\CoreBundle\Watch\ErrorJournalHandler;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ErrorJournalHandler::class)]
final class ErrorJournalHandlerTest extends TestCase
{
    use JournalDatabase;

    private Connection $db;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->db = $this->journalDatabase();
        $this->logger = new Logger('app', [new ErrorJournalHandler(new ErrorJournal($this->db), new MockClock('2026-11-17 10:00:00'), dirname(__DIR__, 4))]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->dropJournalDatabase();
    }

    public function testAnExceptionIsKeptByItsClassFileAndLineWhateverItsMessage(): void
    {
        foreach (['Customer 12 not found', 'Customer 13 not found'] as $message) {
            $this->logger->error('Uncaught', ['exception' => $this->exception($message)]);
        }

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]['occurrences']);
        self::assertSame(RuntimeException::class, $rows[0]['exception_class']);
        self::assertSame('Customer 13 not found', $rows[0]['message']);
        // Relative to the project, not to the server it ran on.
        self::assertSame('src/CoreBundle/Tests/Watch/ErrorJournalHandlerTest.php', $rows[0]['file']);
        self::assertSame('console', $rows[0]['source']);
    }

    public function testAnErrorWithoutExceptionIsKeptByItsMessage(): void
    {
        $this->logger->critical('The SUPER PDP token could not be refreshed');
        $this->logger->critical('The SUPER PDP token could not be refreshed');
        $this->logger->error('Something else');

        self::assertSame([2, 1], array_map(static fn (array $r): int => (int) $r['occurrences'], $this->rows()));
    }

    public function testWhatIsNotTheApplicationFailingStaysOut(): void
    {
        $this->logger->error('Uncaught', ['exception' => new NotFoundHttpException('No route')]);
        $this->logger->error('Uncaught', ['exception' => new AccessDeniedHttpException()]);
        $this->logger->warning('Below the error level');
        (new Logger('deprecation', $this->logger->getHandlers()))->error('Deprecated');

        self::assertSame([], $this->rows());

        // A 5xx is the application failing.
        $this->logger->error('Uncaught', ['exception' => new ServiceUnavailableHttpException()]);
        self::assertCount(1, $this->rows());
    }

    public function testAnExceptionThrownInALibraryIsPlacedWhereOurCodeCalledIt(): void
    {
        try {
            // Thrown inside vendor/symfony/uid, reached from this test.
            Ulid::fromString('not a ulid');
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Uncaught', ['exception' => $e]);
        }

        self::assertStringStartsWith('vendor/', $this->relativeThrowSite($e));
        self::assertSame('src/CoreBundle/Tests/Watch/ErrorJournalHandlerTest.php', $this->rows()[0]['file']);
    }

    public function testTheLevelIsTheRecordsOwn(): void
    {
        $this->logger->log(Level::Alert, 'Disk full', ['exception' => new LogicException('x')]);

        self::assertSame('ALERT', $this->rows()[0]['level']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAllAssociative('SELECT * FROM error_records ORDER BY occurrences DESC, message');
    }

    private function relativeThrowSite(InvalidArgumentException $e): string
    {
        return substr($e->getFile(), strlen(dirname(__DIR__, 4)) + 1);
    }

    private function exception(string $message): RuntimeException
    {
        // Built on one line, so that both share their file and line.
        return new RuntimeException($message);
    }
}
