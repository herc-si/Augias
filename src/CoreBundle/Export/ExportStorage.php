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

namespace Augias\CoreBundle\Export;

use const DIRECTORY_SEPARATOR;
use Augias\CoreBundle\Entity\ExportJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Ulid;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Where the export archives are kept: with the attachments, the one place
 * every container of an install shares.
 *
 * They were under the project's var/, which each container has its own of:
 * the worker wrote the archive where neither the web container (to download
 * it) nor the cron container (to see that a closing company has its archive,
 * and delete it) could find it — on the hosted service, no company closure
 * ever went through (test instance, 26/11/2026).
 *
 * An archive made before keeps its `var/exports/…` path, read from the
 * project directory.
 *
 * @see \Augias\CoreBundle\Tests\Export\ExportStorageTest
 */
final readonly class ExportStorage
{
    private const string LEGACY_PREFIX = 'var/exports/';

    public function __construct(
        #[Autowire(env: 'AUGIAS_ATTACHMENTS_DIR')]
        private string $attachmentsDir,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * Where a job's archive goes, relative to the attachments.
     */
    public function relativePathFor(ExportJob $job): string
    {
        return sprintf('exports/%s/%s.zip', $job->getCompany()->getId()->toBase58(), $job->getId()->toBase58());
    }

    /**
     * The archive on disk; null when the job has none. Only within the
     * export directories: a malformed path never reads another file.
     */
    public function absolutePath(ExportJob $job): ?string
    {
        $path = $job->getArchivePath();

        if (null === $path) {
            return null;
        }

        return str_starts_with($path, self::LEGACY_PREFIX)
            ? rtrim($this->projectDir, '/') . '/' . $path
            : $this->absolute($path);
    }

    public function absolute(string $relativePath): string
    {
        return rtrim($this->attachmentsDir, '/') . '/' . $relativePath;
    }

    /**
     * Whether a file is one of the archives, and nothing else.
     */
    public function contains(string $absolutePath): bool
    {
        $resolved = realpath($absolutePath);

        if (false === $resolved) {
            return false;
        }

        foreach ($this->roots() as $root) {
            $root = realpath($root);

            if (false !== $root && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A company's archive directories, where they are kept now and where
     * they were.
     *
     * @return list<string>
     */
    public function companyDirectories(Ulid $companyId): array
    {
        return [
            $this->absolute('exports/' . $companyId->toBase58()),
            rtrim($this->projectDir, '/') . '/' . self::LEGACY_PREFIX . $companyId->toBase58(),
        ];
    }

    /**
     * @return list<string>
     */
    private function roots(): array
    {
        return [$this->absolute('exports'), rtrim($this->projectDir, '/') . '/' . rtrim(self::LEGACY_PREFIX, '/')];
    }
}
