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

namespace Augias\DashboardBundle\Tests\Widgets;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\DashboardBundle\Widgets\DocumentFollowUpWidget;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

#[CoversClass(DocumentFollowUpWidget::class)]
final class DocumentFollowUpWidgetTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTheLatestClientSideEventsWithTheirDocuments(): void
    {
        $quote = QuoteFactory::createOne([
            'company' => $this->company,
            'client' => ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'name' => 'Boulangerie Martin']),
            'status' => QuoteStatus::Pending,
            'archived' => null,
            'quoteId' => 'DEV-42',
        ]);

        $recorder = self::getContainer()->get(DocumentActivityRecorder::class);
        self::assertInstanceOf(DocumentActivityRecorder::class, $recorder);
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Status, 'publish');
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::SendFailed, 'Connection refused');
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Sent, recipients: ['client@example.org']);
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Viewed, userAgent: 'python-requests/2.32');

        $widget = self::getContainer()->get(DocumentFollowUpWidget::class);
        self::assertInstanceOf(DocumentFollowUpWidget::class, $widget);
        $data = $widget->getData();

        // Status steps and automated visits are left out.
        self::assertSame(
            ['sent', 'send_failed'],
            array_map(static fn (array $line): string => $line['entry']->getType()->value, $data['lines']),
        );
        self::assertSame('DEV-42', $data['lines'][0]['document']->getQuoteId());
        self::assertSame(1, $data['failedRecently']);

        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render($widget->getTemplate(), $data);

        self::assertStringContainsString('DEV-42', $html);
        self::assertStringContainsString('Boulangerie Martin', $html);
        self::assertStringContainsString('/quotes/view/' . $quote->getId(), $html);
    }

    public function testNothingYet(): void
    {
        $widget = self::getContainer()->get(DocumentFollowUpWidget::class);
        self::assertInstanceOf(DocumentFollowUpWidget::class, $widget);

        $data = $widget->getData();

        self::assertSame([], $data['lines']);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertStringContainsString('data-test="follow-up-empty"', $twig->render($widget->getTemplate(), $data));
    }
}
