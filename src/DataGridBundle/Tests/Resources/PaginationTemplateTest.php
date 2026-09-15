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

namespace Augias\DataGridBundle\Tests\Resources;

use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function range;
use function str_contains;
use function substr_count;

/**
 * The page links belong to the live component, not to a route.
 *
 * Pagerfanta builds hrefs from the current route, and inside a live component
 * that route is `ux_live_component` — so after any live re-render the links
 * pointed at /_components/DataGrid?page=2, and clicking one navigated the
 * browser to an endpoint that only answers the component's own requests. The
 * reply was "Unable to find the controller for path", which is a confusing way
 * to say "this URL was never meant to be visited".
 *
 * The component's own tests are skipped as flaky, which is why nothing caught
 * this; the template can be rendered on its own, and that is enough to pin the
 * markup that matters.
 */
#[CoversNothing]
final class PaginationTemplateTest extends KernelTestCase
{
    public function testThePageLinksUpdateTheComponentRatherThanNavigate(): void
    {
        $html = $this->render(currentPage: 2);

        self::assertStringContainsString('data-model="page"', $html);
        self::assertStringContainsString('data-action="live#update:prevent"', $html);
        self::assertStringContainsString('data-value="3"', $html);

        // Every anchor is inert: the only href is the placeholder, so a click
        // that the live controller does not handle goes nowhere instead of
        // going somewhere wrong.
        self::assertSame(substr_count($html, '<a '), substr_count($html, 'href="#"'));
        self::assertFalse(str_contains($html, '/_components'));
    }

    public function testTheEdgesAreDisabledRatherThanLinkedPastTheEnd(): void
    {
        $first = $this->render(currentPage: 1);
        $last = $this->render(currentPage: 10);

        self::assertStringContainsString('page-item disabled', $first);
        self::assertStringContainsString('page-item disabled', $last);
    }

    private function render(int $currentPage): string
    {
        self::bootKernel();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $paginator = new Pagerfanta(new FixedAdapter(100, range(1, 10)));
        $paginator->setMaxPerPage(10);
        $paginator->setCurrentPage($currentPage);

        return $twig->render('@AugiasDataGrid/Components/_pagination.html.twig', ['paginator' => $paginator]);
    }
}
