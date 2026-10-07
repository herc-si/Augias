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

namespace Augias\ElectronicInvoicingBundle\Tests\Twig\Components;

use Augias\ElectronicInvoicingBundle\Twig\Components\PendingReceipts;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

#[CoversClass(PendingReceipts::class)]
final class PendingReceiptsTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use InteractsWithTwigComponents;

    /**
     * This panel is the only remaining way into the received-invoices list now
     * that it has no sidebar entry, so it has to render its link even when
     * nothing is waiting — otherwise the page becomes unreachable.
     */
    public function testRendersLinkToReceivedInvoicesWhenNothingIsPending(): void
    {
        // A real page has one: the "Synchroniser maintenant" form's token lives in it.
        $request = Request::create('/bills');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        $rendered = $this->renderTwigComponent('PendingReceipts')->toString();

        self::assertStringContainsString('/electronic-invoicing/incoming', $rendered);
        self::assertStringContainsString('/electronic-invoicing/sync', $rendered);
        self::assertStringContainsString('Electronic invoicing', $rendered);
    }
}
