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

namespace Augias\ElectronicInvoicingBundle\Tests\Twig;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\ElectronicInvoicingBundle\Twig\ElectronicInvoiceExtension;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

#[CoversClass(ElectronicInvoiceExtension::class)]
final class ElectronicInvoiceExtensionTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testResolveProcessingStatusDelegatesToTheSubmissionsProvider(): void
    {
        $submission = new ElectronicInvoiceSubmission()
            ->setProvider('super_pdp')
            ->setSuccess(true)
            ->setStatusCode('fr:205');

        $status = $this->extension()->resolveProcessingStatus($submission);

        self::assertSame(ElectronicInvoiceProcessingStatus::Accepted, $status);
    }

    public function testResolveProcessingStatusFallsBackToSuccessFlagForAnUnknownProvider(): void
    {
        $submission = new ElectronicInvoiceSubmission()
            ->setProvider('a_removed_provider')
            ->setSuccess(false);

        $status = $this->extension()->resolveProcessingStatus($submission);

        self::assertSame(ElectronicInvoiceProcessingStatus::Rejected, $status);
    }

    /**
     * The list shows where the invoice stands now: the latest submission's
     * status, whatever an earlier one said — and nothing for an invoice that
     * never went out.
     */
    public function testTheListShowsTheLatestSubmissionsStatus(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $refused = new ElectronicInvoiceSubmission()->setProvider('super_pdp')->setSuccess(false);
        $refused->setCreated(new DateTimeImmutable('2026-09-24 10:00'));
        $disputed = new ElectronicInvoiceSubmission()->setProvider('super_pdp')->setSuccess(true)->setStatusCode('fr:207');
        $disputed->setCreated(new DateTimeImmutable('2026-09-25 10:00'));

        $label = $this->extension()->renderLatestStatusLabel($twig, [$disputed, $refused]);

        self::assertStringContainsString('Disputed', $label);
        self::assertStringNotContainsString('Rejected', $label);
        self::assertSame('', $this->extension()->renderLatestStatusLabel($twig, []));
    }

    /**
     * ElectronicInvoiceExtension is only wired into the container via the
     * `twig.extension` tag, so it isn't retrievable as a standalone service —
     * fetch it back from the actual Twig environment it registers itself with.
     */
    private function extension(): ElectronicInvoiceExtension
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $extension = $twig->getExtension(ElectronicInvoiceExtension::class);
        self::assertInstanceOf(ElectronicInvoiceExtension::class, $extension);

        return $extension;
    }
}
