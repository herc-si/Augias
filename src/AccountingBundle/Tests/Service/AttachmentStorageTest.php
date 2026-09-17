<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Tests\Service;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Service\AttachmentStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;

/**
 * The refusals, which are the part of the storage worth pinning: everything
 * else about it is exercised end to end by
 * {@see \Augias\AccountingBundle\Tests\Functional\EntryAttachmentTest}.
 */
#[CoversClass(AttachmentStorage::class)]
final class AttachmentStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/augias-storage-' . new Ulid();
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    /**
     * A stored path is written by this class and never by a user, but this is
     * the one place a string out of the database becomes a filesystem read — so
     * it is the place to refuse one that climbs out of the root.
     */
    public function testAPathThatClimbsOutOfTheRootIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        new AttachmentStorage($this->root, new Filesystem())
            ->path($this->attachment('../../etc/passwd'));
    }

    /**
     * A row whose file is gone — a restore that put the database back without
     * the volume. Saying so beats serving an empty response as though nothing
     * were wrong.
     */
    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        new AttachmentStorage($this->root, new Filesystem())
            ->path($this->attachment('company/2026/gone.pdf'));
    }

    public function testRemovingAnAttachmentWhoseFileIsAlreadyGoneIsNotAnError(): void
    {
        new AttachmentStorage($this->root, new Filesystem())
            ->remove($this->attachment('company/2026/gone.pdf'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Nor does removal follow a traversing path: the row is going away either
     * way, and nothing outside the root is this class's to delete.
     */
    public function testRemovalDoesNotFollowAPathOutOfTheRoot(): void
    {
        $outside = $this->root . '/outside.pdf';
        file_put_contents($outside, 'kept');

        mkdir($this->root . '/inside');
        new AttachmentStorage($this->root . '/inside', new Filesystem())
            ->remove($this->attachment('../outside.pdf'));

        self::assertFileExists($outside);
    }

    private function attachment(string $storagePath): EntryAttachment
    {
        return new EntryAttachment()
            ->setFilename('facture.pdf')
            ->setMimeType('application/pdf')
            ->setSize(1024)
            ->setChecksum('0')
            ->setStoragePath($storagePath);
    }
}
