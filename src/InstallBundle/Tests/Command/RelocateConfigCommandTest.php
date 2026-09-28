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

namespace Augias\InstallBundle\Tests\Command;

use Augias\InstallBundle\Command\RelocateConfigCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(RelocateConfigCommand::class)]
final class RelocateConfigCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/' . uniqid('relocate-', true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testTheDatabaseFollowsTheConfiguration(): void
    {
        $from = '/home/jo/.SolidInvoice/app_abc/config/env';
        $to = '/Users/jo/Library/Application Support/Augias/env';

        $vault = new SodiumVault($this->dir . '/env');
        $vault->generateKeys();
        $vault->seal('AUGIAS_DATABASE_URL', 'sqlite:///' . $from . '/db/augias.db');
        $vault->seal('AUGIAS_APP_SECRET', 'unrelated');

        $tester = new CommandTester(new Command(null, new RelocateConfigCommand($vault, $to)));
        $tester->execute(['from' => $from . '/']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            'sqlite:////Users/jo/Library/Application%20Support/Augias/env/db/augias.db',
            $vault->reveal('AUGIAS_DATABASE_URL'),
        );
        self::assertSame('unrelated', $vault->reveal('AUGIAS_APP_SECRET'));
        self::assertStringContainsString('AUGIAS_DATABASE_URL', $tester->getDisplay());
        self::assertStringNotContainsString('AUGIAS_APP_SECRET', $tester->getDisplay());
    }

    public function testAnEncodedPathIsRewrittenEncoded(): void
    {
        $from = '/home/jo doe/.SolidInvoice/app_abc/config/env';

        $vault = new SodiumVault($this->dir . '/env');
        $vault->generateKeys();
        $vault->seal('AUGIAS_DATABASE_URL', 'sqlite:////home/jo%20doe/.SolidInvoice/app_abc/config/env/db/augias.db');

        $tester = new CommandTester(new Command(null, new RelocateConfigCommand($vault, '/home/jo doe/.config/Augias/env')));
        $tester->execute(['from' => $from]);

        self::assertSame('sqlite:////home/jo%20doe/.config/Augias/env/db/augias.db', $vault->reveal('AUGIAS_DATABASE_URL'));
    }
}
