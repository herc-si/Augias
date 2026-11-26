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

namespace Augias\CoreBundle\Tests\Export;

use Augias\CoreBundle\Entity\ExportJob;
use Augias\CoreBundle\Export\Enum\ExportFormat;
use Augias\CoreBundle\Export\ExportStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(ExportStorage::class)]
final class ExportStorageTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/export-storage-' . uniqid();
        new Filesystem()->mkdir([$this->base . '/attachments/exports/c', $this->base . '/project/var/exports/c', $this->base . '/project/var/secret']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->base);
    }

    /**
     * With the attachments, which every container shares: the worker writes
     * the archive, the web and cron containers find it (26/11/2026).
     */
    public function testANewArchiveIsKeptWithTheAttachments(): void
    {
        $job = new ExportJob(new Ulid(), ExportFormat::Csv);
        $job->markCompleted('exports/c/job.zip', 1);

        self::assertSame($this->base . '/attachments/exports/c/job.zip', $this->storage()->absolutePath($job));
    }

    public function testAnArchiveMadeBeforeIsReadFromTheProject(): void
    {
        $job = new ExportJob(new Ulid(), ExportFormat::Csv);
        $job->markCompleted('var/exports/c/job.zip', 1);

        self::assertSame($this->base . '/project/var/exports/c/job.zip', $this->storage()->absolutePath($job));
    }

    public function testOnlyArchivesAreServed(): void
    {
        new Filesystem()->dumpFile($this->base . '/attachments/exports/c/job.zip', 'zip');
        new Filesystem()->dumpFile($this->base . '/project/var/exports/c/old.zip', 'zip');
        new Filesystem()->dumpFile($this->base . '/project/var/secret/key', 'no');

        self::assertTrue($this->storage()->contains($this->base . '/attachments/exports/c/job.zip'));
        self::assertTrue($this->storage()->contains($this->base . '/project/var/exports/c/old.zip'));
        self::assertFalse($this->storage()->contains($this->base . '/attachments/exports/../../project/var/secret/key'));
    }

    private function storage(): ExportStorage
    {
        return new ExportStorage($this->base . '/attachments', $this->base . '/project');
    }
}
