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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Twig\Extension\DocumentActivityExtension;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The "Activity" column of the quote list: one badge saying where a quote
 * stands with its client.
 */
#[CoversClass(DocumentActivityExtension::class)]
final class DocumentActivityBadgeTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private const string BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    public function testAFinalisedQuoteNobodySentSaysSo(): void
    {
        self::assertSame('never_sent', $this->state($this->quote(QuoteStatus::Pending)));
    }

    public function testADraftSaysNothing(): void
    {
        self::assertNull($this->state($this->quote(QuoteStatus::Draft)));
    }

    public function testAnAnswerOutweighsAReadingWhichOutweighsASending(): void
    {
        $quote = $this->quote(QuoteStatus::Pending);

        $this->record($quote, DocumentActivityType::Sent);
        self::assertSame('sent', $this->state($quote));

        // A mail filter opening the link is not the client reading it.
        $this->record($quote, DocumentActivityType::Viewed, 'Mozilla/5.0 (compatible; Microsoft Outlook Link Preview)');
        self::assertSame('sent', $this->state($quote));

        $this->record($quote, DocumentActivityType::Viewed, self::BROWSER);
        self::assertSame('seen', $this->state($quote));

        $this->record($quote, DocumentActivityType::ClientAccepted, self::BROWSER);
        self::assertSame('accepted', $this->state($quote));
    }

    public function testAFailureShowsOnlyWhileNothingLeft(): void
    {
        $quote = $this->quote(QuoteStatus::Pending);

        $this->record($quote, DocumentActivityType::SendFailed);
        self::assertSame('failed', $this->state($quote));

        $this->record($quote, DocumentActivityType::Sent);
        self::assertSame('sent', $this->state($quote));
    }

    private function state(Quote $quote): ?string
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate('{{ document_activity_badge(quote) }}')->render(['quote' => $quote]);

        return 1 === preg_match('/data-activity-state="([a-z_]+)"/', $html, $match) ? $match[1] : null;
    }

    private function record(Quote $quote, DocumentActivityType $type, ?string $userAgent = null): void
    {
        $recorder = self::getContainer()->get(DocumentActivityRecorder::class);
        self::assertInstanceOf(DocumentActivityRecorder::class, $recorder);
        $recorder->record($quote, $quote->getCompany(), $type, 'ClientAccepted' === $type->name ? 'Paul Martin' : null, userAgent: $userAgent);
    }

    private function quote(QuoteStatus $status): Quote
    {
        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']),
            'status' => $status,
            'archived' => null,
        ]);
    }
}
