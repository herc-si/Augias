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

namespace Augias\QuoteBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Action\ClientAnswer;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use function array_filter;
use function array_values;

/**
 * The client answering a quote from their link, without an account.
 */
#[CoversClass(ClientAnswer::class)]
final class ClientAnswerTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testTheClientAcceptsUnderTheirName(): void
    {
        $quote = $this->createQuote();

        $this->answer($quote, ['answer' => 'accept', 'name' => '  Paul Martin ', 'agree' => '1']);

        self::assertResponseRedirects('/view/quote/' . $quote->getUuid());
        $quote = $this->reload($quote);
        self::assertSame(QuoteStatus::Accepted, $quote->getStatus());
        // Accepting does what it does from inside the app, which no longer
        // includes making the invoice.
        self::assertNull($quote->getInvoice());

        $answers = $this->history($quote, DocumentActivityType::ClientAccepted);
        self::assertCount(1, $answers);
        self::assertSame('Paul Martin', $answers[0]->getDetail());
        self::assertSame('127.0.0.1', $answers[0]->getIpAddress());
        self::assertNull($answers[0]->getUser());

        // The answer stands for the step: no second, nameless "accepted".
        self::assertSame([], $this->history($quote, DocumentActivityType::Status, 'accept'));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('in the name of Paul Martin', $crawler->filter('[data-test="client-answer"]')->text());
        self::assertCount(0, $crawler->filter('[data-test="accept-form"]'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteAcceptances(): iterable
    {
        yield 'no box ticked' => [['answer' => 'accept', 'name' => 'Paul Martin']];
        yield 'no name' => [['answer' => 'accept', 'name' => '   ', 'agree' => '1']];
    }

    /**
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteAcceptances')]
    public function testAnAcceptanceNeedsTheNameAndTheBox(array $fields): void
    {
        $quote = $this->createQuote();

        $this->answer($quote, $fields);

        self::assertSame(QuoteStatus::Pending, $this->reload($quote)->getStatus());
        self::assertSame([], $this->history($quote, DocumentActivityType::ClientAccepted));
    }

    public function testTheClientDeclinesWithAReason(): void
    {
        $quote = $this->createQuote();

        $this->answer($quote, ['answer' => 'decline', 'reason' => 'Trop cher pour cette année.']);

        self::assertSame(QuoteStatus::Declined, $this->reload($quote)->getStatus());
        $answers = $this->history($quote, DocumentActivityType::ClientDeclined);
        self::assertCount(1, $answers);
        self::assertSame('Trop cher pour cette année.', $answers[0]->getDetail());
    }

    /**
     * Valid until yesterday: the page offers no acceptance, and one sent
     * anyway, from a page opened while it was still valid, is turned down.
     */
    public function testAnExpiredQuoteCannotBeAccepted(): void
    {
        $quote = $this->createQuote();
        $crawler = $this->client->request('GET', '/view/quote/' . $quote->getUuid());
        $token = (string) $crawler->filter('[data-test="accept-form"] input[name="_token"]')->attr('value');

        $this->expire($quote);

        $crawler = $this->client->request('GET', '/view/quote/' . $quote->getUuid());
        self::assertCount(0, $crawler->filter('[data-test="accept-form"]'));
        self::assertStringContainsString('no longer valid', $crawler->filter('[data-test="client-answer"]')->text());

        $this->client->request('POST', '/view/quote/' . $quote->getUuid() . '/answer', [
            'answer' => 'accept', 'name' => 'Paul Martin', 'agree' => '1', '_token' => $token,
        ]);

        self::assertSame(QuoteStatus::Pending, $this->reload($quote)->getStatus());
        self::assertSame([], $this->history($quote, DocumentActivityType::ClientAccepted));
    }

    public function testAForgedFormChangesNothing(): void
    {
        $quote = $this->createQuote();

        $this->client->request('POST', '/view/quote/' . $quote->getUuid() . '/answer', [
            'answer' => 'accept', 'name' => 'Paul Martin', 'agree' => '1', '_token' => 'forged',
        ]);

        self::assertSame(QuoteStatus::Pending, $this->reload($quote)->getStatus());
    }

    /**
     * Opens the client's page, then sends the form it carries, as a browser
     * would: with the page's own token.
     *
     * @param array<string, string> $fields
     */
    private function answer(Quote $quote, array $fields): void
    {
        $crawler = $this->client->request('GET', '/view/quote/' . $quote->getUuid());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('[data-test="accept-form"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/view/quote/' . $quote->getUuid() . '/answer', $fields + ['_token' => (string) $token]);
    }

    private function expire(Quote $quote): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $fresh = $entityManager->find(Quote::class, $quote->getId());
        self::assertInstanceOf(Quote::class, $fresh);
        $fresh->setDue(CarbonImmutable::yesterday());
        $entityManager->flush();
        $entityManager->clear();
    }

    private function createQuote(): Quote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['client' => $client, 'company' => $this->company, 'email' => 'client@example.org']);

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => QuoteStatus::Pending,
            'archived' => null,
            'due' => CarbonImmutable::now()->addDays(30),
            'users' => [$contact],
        ]);
    }

    private function reload(Quote $quote): Quote
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $fresh = $entityManager->find(Quote::class, $quote->getId());
        self::assertInstanceOf(Quote::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<DocumentActivity>
     */
    private function history(Quote $quote, DocumentActivityType $type, ?string $detail = null): array
    {
        $repository = self::getContainer()->get(DocumentActivityRepository::class);
        self::assertInstanceOf(DocumentActivityRepository::class, $repository);

        return array_values(array_filter(
            $repository->forDocument(RecordKind::Quote, $quote->getId()),
            static fn (DocumentActivity $a): bool => $a->getType() === $type && (null === $detail || $a->getDetail() === $detail),
        ));
    }
}
