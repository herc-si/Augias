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

namespace Augias\CoreBundle\Tests\Functional;

use Augias\CoreBundle\Action\TemplatePreviewAction;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\PreviewInvoiceFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Group('functional')]
final class TemplatePreviewActionTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testRendersEveryDiscoveredTemplateWithSampleData(): void
    {
        $registry = self::getContainer()->get(BillingTemplateRegistry::class);
        $action = $this->createAction($registry);

        self::assertNotEmpty($registry->getSlugs());

        foreach ($registry->getSlugs() as $slug) {
            $response = $action($slug);

            self::assertSame(200, $response->getStatusCode(), $slug);
            self::assertStringContainsString('INV-' . date('Y') . '-0042', (string) $response->getContent(), $slug);
            self::assertStringContainsString('Acme Studios', (string) $response->getContent(), $slug);
            // The sample carries units, as a real invoice's lines do.
            self::assertStringContainsString("2\u{00A0}days", (string) $response->getContent(), $slug);
            // And a discount: choosing a design shows where it puts one (06/10/2026).
            self::assertStringContainsString('Discount', (string) $response->getContent(), $slug);
        }
    }

    public function testRendersTheDefaultTemplate(): void
    {
        $action = $this->createAction(self::getContainer()->get(BillingTemplateRegistry::class));

        $response = $action(BillingTemplateRegistry::DEFAULT_SLUG);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('INV-' . date('Y') . '-0042', (string) $response->getContent());
        self::assertStringNotContainsString('{PAGENO}', (string) $response->getContent());
        // The default is an mPDF page document; the preview must constrain it
        // to a sheet instead of letting it stretch edge-to-edge.
        self::assertStringContainsString('max-width: 800px', (string) $response->getContent());
    }

    public function testRejectsUnknownTemplates(): void
    {
        $action = $this->createAction(self::getContainer()->get(BillingTemplateRegistry::class));

        $this->expectException(NotFoundHttpException::class);

        $action('does-not-exist');
    }

    private function createAction(BillingTemplateRegistry $registry): TemplatePreviewAction
    {
        return new TemplatePreviewAction(
            $registry,
            new PreviewInvoiceFactory(self::getContainer()->get(SystemConfig::class), self::getContainer()->get(TranslatorInterface::class)),
            self::getContainer()->get(Environment::class),
        );
    }
}
