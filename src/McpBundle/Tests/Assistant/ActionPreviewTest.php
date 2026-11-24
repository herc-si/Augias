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

namespace Augias\McpBundle\Tests\Assistant;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Assistant\ActionPreview;
use Augias\McpBundle\Tests\Functional\SignsInMember;
use Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;
use function array_column;
use function str_replace;

/**
 * What a proposed change shows before it is confirmed: names, not
 * identifiers; amounts, not cents; every line.
 */
#[CoversClass(ActionPreview::class)]
#[Group('functional')]
final class ActionPreviewTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use SignsInMember;

    public function testAQuoteShowsItsClientAndItsLinesInTheClientsCurrency(): void
    {
        $this->signInMember();
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Dupont SARL', 'currencyCode' => 'EUR']);
        $previous = Locale::getDefault();
        Locale::setDefault('fr_FR');

        try {
            $preview = $this->preview()->describe('create_quote', [
                'client_id' => $client->getId()->toRfc4122(),
                'lines' => [
                    ['description' => 'Conseil', 'price' => 8000, 'qty' => 3],
                    ['description' => 'Déplacement', 'price' => 4550, 'qty' => 1],
                ],
                'subject' => 'Audit du parc',
                'notes' => null,
            ]);
        } finally {
            Locale::setDefault($previous);
        }

        self::assertSame('Create a quote', $preview['title']);
        self::assertSame(['Client', 'Lines', 'Subject'], array_column($preview['details'], 'label'));
        self::assertSame('Dupont SARL', $preview['details'][0]['value']);
        self::assertSame("3 × Conseil — 80,00\u{a0}€\n1 × Déplacement — 45,50\u{a0}€", str_replace("\u{202f}", "\u{a0}", $preview['details'][1]['value']));
    }

    public function testAnIdentifierOfNothingShowsAsUnknown(): void
    {
        $this->signInMember();

        $preview = $this->preview()->describe('send_invoice', ['invoice_id' => new Ulid()->toRfc4122()]);

        self::assertSame([['label' => 'Invoice', 'value' => 'Unknown']], $preview['details']);
    }

    private function preview(): ActionPreview
    {
        $preview = self::getContainer()->get(ActionPreview::class);
        self::assertInstanceOf(ActionPreview::class, $preview);

        return $preview;
    }
}
