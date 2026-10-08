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
use Augias\CoreBundle\Action\ViewBilling;
use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Listener\DocumentEmailActivityListener;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Email\QuoteEmail;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Mailer\QuoteMailer;
use Augias\QuoteBundle\Model\Graph;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Twig\Environment;
use function array_map;

#[CoversClass(DocumentActivityRecorder::class)]
#[CoversClass(DocumentEmailActivityListener::class)]
final class DocumentActivityTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testSendingAQuoteRecordsWhenAndToWhom(): void
    {
        $quote = $this->createQuote(QuoteStatus::Pending);

        $mailer = self::getContainer()->get(QuoteMailer::class);
        self::assertInstanceOf(QuoteMailer::class, $mailer);
        $mailer->send($quote);

        $sent = $this->history($quote, DocumentActivityType::Sent);

        self::assertCount(1, $sent);
        self::assertSame(['client@example.org'], $sent[0]->getRecipients());
    }

    public function testPublishingIsAStepButNotASending(): void
    {
        $quote = $this->createQuote(QuoteStatus::Draft);

        $workflow = self::getContainer()->get('state_machine.quote');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($quote, Graph::TRANSITION_PUBLISH);

        $steps = $this->history($quote, DocumentActivityType::Status);

        self::assertSame(['publish'], array_map(static fn (DocumentActivity $a): ?string => $a->getDetail(), $steps));
        self::assertSame([], $this->history($quote, DocumentActivityType::Sent));
    }

    public function testAFailedSendingIsRecordedWithItsReason(): void
    {
        $quote = $this->createQuote(QuoteStatus::Pending);
        $email = new QuoteEmail($quote);
        $email->to('client@example.org');

        $listener = self::getContainer()->get(DocumentEmailActivityListener::class);
        self::assertInstanceOf(DocumentEmailActivityListener::class, $listener);
        $listener->onFailed(new FailedMessageEvent($email, new TransportException('Connection refused')));

        $failed = $this->history($quote, DocumentActivityType::SendFailed);

        self::assertCount(1, $failed);
        self::assertSame('Connection refused', $failed[0]->getDetail());
        self::assertSame(['client@example.org'], $failed[0]->getRecipients());
    }

    /**
     * Opening the client's link is a reading; reloading it straight away is
     * the same reading, and the PDF is a download of its own.
     */
    public function testTheClientOpeningTheLinkIsRecordedOnce(): void
    {
        $quote = $this->createQuote(QuoteStatus::Pending);
        $action = $this->buildViewAction(loggedIn: false);
        $uuid = $quote->getUuid()->toString();

        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
        $action->quoteAction(Request::create('/view/quote/' . $uuid, server: ['HTTP_USER_AGENT' => $browser]), $uuid);
        $action->quoteAction(Request::create('/view/quote/' . $uuid, server: ['HTTP_USER_AGENT' => $browser]), $uuid);

        $viewed = $this->history($quote, DocumentActivityType::Viewed);

        self::assertCount(1, $viewed);
        self::assertNull($viewed[0]->getUser());
        self::assertFalse($viewed[0]->isLikelyAutomated());
    }

    public function testTheCompanysOwnUsersAreNotTheClient(): void
    {
        $quote = $this->createQuote(QuoteStatus::Pending);
        $uuid = $quote->getUuid()->toString();

        $this->buildViewAction(loggedIn: true)->quoteAction(Request::create('/view/quote/' . $uuid), $uuid);

        self::assertSame([], $this->history($quote, DocumentActivityType::Viewed));
    }

    private function createQuote(QuoteStatus $status): Quote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['client' => $client, 'company' => $this->company, 'email' => 'client@example.org']);

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => $status,
            'archived' => null,
            'users' => [$contact],
        ]);
    }

    /**
     * @return list<DocumentActivity>
     */
    private function history(Quote $quote, DocumentActivityType $type): array
    {
        $repository = self::getContainer()->get(DocumentActivityRepository::class);
        self::assertInstanceOf(DocumentActivityRepository::class, $repository);

        return array_values(array_filter(
            $repository->forDocument(RecordKind::Quote, $quote->getId()),
            static fn (DocumentActivity $a): bool => $a->getType() === $type,
        ));
    }

    private function buildViewAction(bool $loggedIn): ViewBilling
    {
        $container = self::getContainer();

        $gate = $this->createStub(EmailVerificationGateInterface::class);
        $gate->method('isCompanyGated')->willReturn(false);

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn($loggedIn);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/quotes/view');

        return new ViewBilling(
            $container->get('doctrine'),
            $authChecker,
            $router,
            $container->get(CompanySelector::class),
            $container->get(Generator::class),
            $container->get(Environment::class),
            $gate,
            $container->get(BillingTemplateResolver::class),
            $container->get(DocumentActivityRecorder::class),
        );
    }
}
