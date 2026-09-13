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

namespace Augias\InvoiceBundle\Tests\Functional\Email;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\AugiasCoreBundle;
use Augias\CoreBundle\Entity\Discount;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Renders each invoice email template against a stable fixture and
 * snapshots the HTML. Re-run with `--update-snapshots` to refresh after
 * intentional template changes; the captured `.html` files double as
 * a visual gallery of the final email output.
 */
final class TemplateEmailSnapshotTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use MatchesSnapshots;

    private const string INVOICE_ID = '181aaf4a-0097-11ef-9b64-5a2cf21a5680';

    /**
     * The footer prints the running version, which would otherwise make
     * every release rewrite all of these snapshots.
     */
    private const string VERSION_PLACEHOLDER = '0.0.0-test';

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twig = self::getContainer()->get('twig');
    }

    /**
     * Slugs are discovered from the filesystem so a newly added template
     * directory is covered automatically (its snapshot is created on the
     * first `--update-snapshots` run).
     *
     * @return iterable<string, array{string}>
     */
    public static function templateProvider(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/Resources/views/Templates/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $slug = basename($directory);

            if (! str_starts_with($slug, '_')) {
                yield $slug => [$slug];
            }
        }
    }

    #[DataProvider('templateProvider')]
    public function testEmailTemplateMatchesSnapshot(string $slug): void
    {
        $invoice = $this->createFixtureInvoice();

        $rendered = $this->twig->render(
            sprintf('@AugiasInvoice/Templates/%s/email.html.twig', $slug),
            ['invoice' => $invoice]
        );

        $this->assertMatchesHtmlSnapshot(
            str_replace(AugiasCoreBundle::VERSION, self::VERSION_PLACEHOLDER, $rendered)
        );
    }

    private function createFixtureInvoice(): Invoice
    {
        $client = ClientFactory::createOne([
            'company' => $this->company,
            'currencyCode' => 'USD',
            'name' => 'Acme Corporation',
        ]);

        $contact = ContactFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $invoice = InvoiceFactory::new()
            ->withoutPersisting()
            ->create([
                'company' => $this->company,
                'client' => $client,
                'status' => InvoiceStatus::Pending,
                'total' => 150000,
                'balance' => 150000,
                'baseTotal' => 150000,
                'tax' => 0,
                'created' => new DateTimeImmutable('2024-01-15', new DateTimeZone('UTC')),
                'invoiceDate' => new DateTimeImmutable('2024-01-15', new DateTimeZone('UTC')),
                'due' => new DateTimeImmutable('2024-02-15', new DateTimeZone('UTC')),
                'terms' => 'Payment due within 30 days.',
                'notes' => 'Thank you for your business.',
                'discount' => new Discount()
                    ->setType(null),
                'lines' => [
                    new Line()
                        ->setDescription('Sample line item')
                        ->setPrice(75000)
                        ->setQty(2)
                        ->updateTotal(),
                ],
                'users' => [$contact],
            ]);

        $invoice
            ->setId(Ulid::fromString(self::INVOICE_ID))
            ->setUuid(Uuid::fromString(self::INVOICE_ID))
            ->setInvoiceId('INV-2024-001');

        return $invoice;
    }
}
