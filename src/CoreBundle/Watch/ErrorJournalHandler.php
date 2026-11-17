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

namespace Augias\CoreBundle\Watch;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use function in_array;
use function is_array;
use function sha1;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Sends every error the application logs to the error journal (ErrorJournal),
 * grouped by what it is rather than by when it happened: an exception by its
 * class and the first line of Augias's own code it went through, any other
 * error by its message.
 *
 * Left out, because they are not the application failing: an HTTP error
 * under 500 (a page not found, an access refused), which Symfony logs at the
 * error level too, and deprecation notices.
 */
final class ErrorJournalHandler extends AbstractProcessingHandler
{
    private bool $writing = false;

    public function __construct(
        private readonly ErrorJournal $journal,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        // An error raised while the journal writes is not recorded again.
        if ($this->writing || in_array($record->channel, ['deprecation'], true)) {
            return;
        }

        $exception = $record->context['exception'] ?? null;
        $exception = $exception instanceof Throwable ? $exception : null;

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $this->writing = true;

        try {
            [$file, $line] = null !== $exception ? $this->whereInOurCode($exception) : [null, null];

            $this->journal->recordError(
                null !== $exception
                    ? sha1($exception::class . '|' . $file . '|' . $line)
                    : sha1($record->channel . '|' . $record->message),
                $record->level->getName(),
                self::source($record->channel),
                null !== $exception ? $exception::class : null,
                null !== $exception ? $exception->getMessage() : $record->message,
                $file,
                $line,
                $this->clock->now(),
            );
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Where the error happened: a web request, a console command, or a
     * Messenger worker (which also runs the scheduled tasks).
     */
    private static function source(string $channel): string
    {
        if ('cli' !== PHP_SAPI) {
            return 'web';
        }

        $argv = $_SERVER['argv'] ?? [];

        return 'messenger' === $channel || (is_array($argv) && in_array('messenger:consume', $argv, true)) ? 'worker' : 'console';
    }

    /**
     * The first place in Augias's own code the exception went through: where a
     * reader starts looking, and what tells two errors apart. Thrown from a
     * library, every SQL error would otherwise share the same line of
     * Doctrine. Falls back to where it was thrown.
     *
     * @return array{string, int}
     */
    private function whereInOurCode(Throwable $exception): array
    {
        $own = $this->projectDir . '/src/';

        if (str_starts_with($exception->getFile(), $own)) {
            return [$this->relative($exception->getFile()), $exception->getLine()];
        }

        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line']) && str_starts_with($frame['file'], $own)) {
                return [$this->relative($frame['file']), $frame['line']];
            }
        }

        return [$this->relative($exception->getFile()), $exception->getLine()];
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->projectDir . '/') ? substr($file, strlen($this->projectDir) + 1) : $file;
    }
}
