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

namespace Augias\InstallBundle\Command;

use Augias\InstallBundle\Config\DatabaseConfig;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function is_string;
use function rtrim;
use function sprintf;
use function strtr;

/**
 * Points the configuration at the directory it now lives in, after it was
 * copied from another one.
 *
 * The installer seals absolute paths in the vault: an SQLite database URL
 * names the configuration directory it was created in. Copied elsewhere, the
 * configuration would go on using the database it left behind. The binary
 * runs this once, after recovering what a 4.0.0 binary kept inside its
 * extracted app.
 *
 * @see \Augias\InstallBundle\Tests\Command\RelocateConfigCommandTest
 */
#[AsCommand(
    name: 'augias:relocate-config',
    description: 'Rewrite the paths the configuration holds after it was copied from another directory',
    hidden: true,
)]
final readonly class RelocateConfigCommand
{
    public function __construct(
        private AbstractVault $vault,
        #[Autowire(env: 'AUGIAS_CONFIG_DIR')]
        private string $configDir,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument(description: 'The directory the configuration was copied from')]
        string $from,
    ): int {
        $from = rtrim($from, '/\\');
        $to = rtrim($this->configDir, '/\\');

        if ($from === $to) {
            return Command::SUCCESS;
        }

        // A database URL carries the path percent-encoded, anything else as is.
        $paths = [
            $from => $to,
            DatabaseConfig::encodeDsnPath($from) => DatabaseConfig::encodeDsnPath($to),
        ];

        foreach ($this->vault->list(true) as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            $relocated = strtr($value, $paths);

            if ($relocated !== $value) {
                $this->vault->seal($name, $relocated);
                $output->writeln(sprintf('%s now points at %s', $name, $to));
            }
        }

        return Command::SUCCESS;
    }
}
