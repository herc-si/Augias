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
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use function file_put_contents;
use function getenv;

/**
 * The history card on a quote's page answers the two questions it is there
 * for: was it sent, and did the client open it.
 */
#[CoversNothing]
final class DocumentActivityCardTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    public function testANeverSentQuoteSaysSo(): void
    {
        $quote = $this->createQuote();

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-test="document-activity"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('No sending to the client by email recorded', $card->text());
        self::assertStringContainsString('Not viewed by the client yet', $card->text());
    }

    public function testASentAndOpenedQuoteShowsWhenAndToWhom(): void
    {
        $quote = $this->createQuote();
        $recorder = self::getContainer()->get(DocumentActivityRecorder::class);
        self::assertInstanceOf(DocumentActivityRecorder::class, $recorder);
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Status, 'publish');
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Sent, recipients: ['client@example.org']);
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Viewed, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148');

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-test="document-activity"]')->text();
        self::assertStringContainsString('Sent to the client on', $card);
        self::assertStringContainsString('Viewed by the client on', $card);
        self::assertStringContainsString('to client@example.org', $card);
        self::assertStringContainsString('Finalised (not sent)', $card);

        // A copy of the page to look at, when asked for.
        if (false !== ($path = getenv('DOCUMENT_ACTIVITY_PAGE'))) {
            file_put_contents($path, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * Finalised and never sent: the page says so above the quote, with the
     * button that sends it. Once an email has left, it says nothing.
     */
    public function testAFinalisedQuoteNobodySentIsFlagged(): void
    {
        $quote = $this->createQuote();

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());

        $banner = $crawler->filter('[data-test="not-sent-banner"]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('no sending to the client is recorded', $banner->text());
        self::assertCount(1, $banner->filter('a[href="/quotes/action/send/' . $quote->getId() . '"]'));

        $recorder = self::getContainer()->get(DocumentActivityRecorder::class);
        self::assertInstanceOf(DocumentActivityRecorder::class, $recorder);
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::Sent, recipients: ['client@example.org']);

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());

        self::assertCount(0, $crawler->filter('[data-test="not-sent-banner"]'));
    }

    public function testADraftIsNotFlagged(): void
    {
        $quote = $this->createQuote(QuoteStatus::Draft);

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());

        self::assertCount(0, $crawler->filter('[data-test="not-sent-banner"]'));
    }

    /**
     * The quote as the client accepted it can be downloaded from the card.
     */
    public function testTheAcceptedQuoteIsKeptAndCanBeDownloaded(): void
    {
        $quote = $this->createQuote();
        $storage = self::getContainer()->get(DocumentStorage::class);
        self::assertInstanceOf(DocumentStorage::class, $storage);
        $recorder = self::getContainer()->get(DocumentActivityRecorder::class);
        self::assertInstanceOf(DocumentActivityRecorder::class, $recorder);

        $proof = $storage->storeGenerated('%PDF-accepted', 'devis.pdf', $quote->getCompany());
        $recorder->record($quote, $quote->getCompany(), DocumentActivityType::ClientAccepted, 'Paul Martin', proof: $proof);

        $crawler = $this->client->request('GET', '/quotes/view/' . $quote->getId());
        $link = $crawler->filter('[data-test="document-activity"] a[href^="/activity/"]');
        self::assertCount(1, $link);
        self::assertStringContainsString(substr($proof->checksum, 0, 16), $crawler->filter('[data-test="document-activity"]')->text());

        $this->client->request('GET', (string) $link->attr('href'));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('%PDF-accepted', file_get_contents($response->getFile()->getPathname()));
    }

    /**
     * The quote list carries an "Activity" column with one badge per quote,
     * rendered as HTML rather than escaped.
     */
    public function testTheQuoteListShowsWhereEachQuoteStands(): void
    {
        $this->createQuote();

        $crawler = $this->client->request('GET', '/quotes/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-activity-state="never_sent"]'));
    }

    private function createQuote(QuoteStatus $status = QuoteStatus::Pending): Quote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'name' => 'Boulangerie Martin SARL']);
        $contact = ContactFactory::createOne(['client' => $client, 'company' => $this->company, 'email' => 'client@example.org']);

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => $status,
            'archived' => null,
            'users' => [$contact],
        ]);
    }
}
