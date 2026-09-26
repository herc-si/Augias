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

namespace Augias\CoreBundle\Doctrine\Listener;

use Augias\CoreBundle\Doctrine\ForeignKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use function str_starts_with;

/**
 * The schema commands change tables the way SQLite does it — copy, drop,
 * recreate — and with foreign keys on, the drop fails or cascades into the
 * child tables. They run with the keys suspended, as migrations do (see
 * {@see SuspendForeignKeysDuringMigrations}); the test bootstrap and Foundry's
 * reset go through them too.
 */
final readonly class SuspendForeignKeysForSchemaCommands
{
    private const string PREFIX = 'doctrine:schema:';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[AsEventListener(ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ($this->concerns($event->getCommand()?->getName())) {
            ForeignKeys::suspend($this->connection);
        }
    }

    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->concerns($event->getCommand()?->getName())) {
            ForeignKeys::restore($this->connection);
        }
    }

    private function concerns(?string $name): bool
    {
        return null !== $name && str_starts_with($name, self::PREFIX);
    }
}
