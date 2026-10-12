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

namespace Augias\ElectronicInvoicingBundle\Provider;

use DateTimeImmutable;
use RuntimeException;

/**
 * A platform that shows what it sends the tax administration from the
 * e-reporting data it is given: the declarations so far, and the next one.
 */
interface ElectronicDeclarationsInterface
{
    /**
     * @param array<string, mixed> $config
     *
     * @return list<DeclaredReport>
     *
     * @throws RuntimeException
     */
    public function declarations(array $config): array;

    /**
     * Null when nothing is in the period yet.
     *
     * @param array<string, mixed>    $config
     * @param 'transaction'|'payment' $kind
     *
     * @throws RuntimeException
     */
    public function preview(array $config, string $kind, DateTimeImmutable $date): ?ReportPreview;
}
