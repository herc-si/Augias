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

namespace Augias\InvoiceBundle\Tests\Deposit;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\Action\CreateDeposit;
use Augias\InvoiceBundle\Deposit\DepositBilling;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Twig\Components\CreateInvoice;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Test\Factory\TaxFactory;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Workflow\WorkflowInterface;
use function array_map;
use function array_values;

/**
 * A quote billed in stages: a deposit invoice for a share of it, then a final
 * invoice for the rest, with every rate of tax billed its share each time.
 */
#[CoversClass(DepositBilling::class)]
#[CoversClass(CreateDeposit::class)]
final class DepositBillingTest extends LiveComponentTest
{
    public function testADepositBillsItsShareOfEachTaxTreatment(): void
    {
        $quote = $this->createQuote();

        $deposit = $this->deposits()->draft($quote, BigDecimal::of(30));

        $lines = array_values($deposit->getLines()->toArray());
        self::assertCount(2, $lines, 'One line per rate; the note is left out.');
        // 30 % of 1 000,00 + 200,00 at 20 %, and of 500,00 at 10 %.
        self::assertSame([36000, 15000], array_map(static fn ($line): int => $line->getPrice()->toInt(), $lines));
        self::assertSame(['20.0000', '10.0000'], array_map(static fn ($line): string => $line->getTaxes()->first()->getRateSnapshot(), $lines));
        self::assertStringContainsString('30 %', (string) $lines[0]->getDescription());
        self::assertStringContainsString($quote->getQuoteId(), (string) $lines[0]->getDescription());
        self::assertNull($deposit->getQuote(), 'The link of the final invoice is not taken.');
        self::assertNull($deposit->getDepositForQuote(), 'Tied to the quote when the form is saved, not before.');
    }

    public function testAnIssuedDepositIsTiedToTheQuoteAndLeavesTheFinalInvoiceToMake(): void
    {
        $quote = $this->createQuote();

        $deposit = $this->persist($this->deposits()->draft($quote, BigDecimal::of(30))->setDepositForQuote($quote), issue: true);

        self::assertTrue($deposit->isDeposit());
        self::assertTrue($deposit->isNumbered());
        // 360,00 + 20 % and 150,00 + 10 %.
        self::assertSame(59700, $deposit->getTotal()->toInt());
        self::assertSame(8700, $deposit->getTax()->toInt());

        self::assertCount(1, $quote->getDepositInvoices());
        self::assertNull($quote->getInvoice());
        self::assertTrue($this->deposits()->remainingPercentage($quote)->isEqualTo(70));
        self::assertSame([$deposit], $this->deposits()->issued($quote));
    }

    public function testTheFormTiesTheDepositToTheQuoteWhenItIsSaved(): void
    {
        $quote = $this->createQuote();
        $formManager = self::getContainer()->get(InvoiceFormManager::class);
        self::assertInstanceOf(InvoiceFormManager::class, $formManager);

        $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $formManager->createDTOFromInvoice($this->deposits()->draft($quote, BigDecimal::of(30))), 'depositForQuote' => $quote],
            client: $this->client,
        )->actingAs($this->getUser())->call('saveDraft');

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $fresh = $entityManager->find(Quote::class, $quote->getId());
        self::assertInstanceOf(Quote::class, $fresh);
        self::assertCount(1, $fresh->getDepositInvoices());
        self::assertNull($fresh->getInvoice(), 'The final invoice is still to make.');
    }

    public function testTheFinalInvoiceIsForWhatIsLeft(): void
    {
        $quote = $this->createQuote();
        $this->persist($this->deposits()->draft($quote, BigDecimal::of(30))->setDepositForQuote($quote), issue: true);
        // A second deposit left as a draft: not issued, so not deducted.
        $this->persist($this->deposits()->draft($quote, BigDecimal::of(10))->setDepositForQuote($quote), issue: false);

        self::assertTrue($this->deposits()->remainingPercentage($quote)->isEqualTo(60), 'A draft claims its share all the same.');

        $final = self::getContainer()->get(InvoiceManager::class)->draftFromQuote($quote);
        $this->deposits()->deduct($quote, $final);

        $lines = [];
        foreach ($final->getLines() as $line) {
            if (! $line->isNote()) {
                $lines[] = [$line->getPrice()->toInt(), (string) $line->getQty()->toBigDecimal()->strippedOfTrailingZeros()];
            }
        }

        self::assertSame([[100000, '1'], [20000, '1'], [50000, '1'], [36000, '-1'], [15000, '-1']], $lines);

        $final->setQuote($quote);
        $invoice = $this->persist($final, issue: true);

        // The quote: 1 200,00 + 20 % and 500,00 + 10 % = 1 990,00, less the 597,00 already billed.
        self::assertSame(139300, $invoice->getTotal()->toInt());
        self::assertSame(20300, $invoice->getTax()->toInt());
        self::assertSame(139300, $invoice->getBalance()->toInt());
        self::assertSame(InvoiceStatus::Pending, $invoice->getStatus());
        self::assertFalse($invoice->isDeposit());
    }

    /**
     * Whatever the discount on the quote, the deposit and the final invoice
     * together bill what the quote says, to the cent or one cent of rounding.
     */
    #[DataProvider('discounts')]
    public function testDepositAndFinalInvoiceAddUpToTheQuote(Discount $discount): void
    {
        $quote = $this->createQuote(QuoteStatus::Accepted, $discount);
        self::assertTrue($quote->getTotal()->isPositive());

        $deposit = $this->persist($this->deposits()->draft($quote, BigDecimal::of(30))->setDepositForQuote($quote), issue: true);

        $final = self::getContainer()->get(InvoiceManager::class)->draftFromQuote($quote);
        $this->deposits()->deduct($quote, $final);
        $final->setQuote($quote);
        $final = $this->persist($final, issue: true);

        $billed = $deposit->getTotal()->toBigDecimal()->plus($final->getTotal()->toBigDecimal());
        $gap = $billed->minus($quote->getTotal()->toBigDecimal())->abs();

        self::assertTrue($gap->isLessThanOrEqualTo(1), 'Billed ' . $billed . ' for a quote of ' . $quote->getTotal());
        // About 30 % of the quote, not the whole discount on one of the two.
        self::assertTrue($deposit->getTotal()->toBigDecimal()->multipliedBy(100)->dividedBy($quote->getTotal()->toBigDecimal(), 0, RoundingMode::HalfUp)->isEqualTo(30));
    }

    /**
     * @return iterable<string, array{Discount}>
     */
    public static function discounts(): iterable
    {
        yield 'no discount' => [new Discount()];

        $percentage = new Discount();
        $percentage->setType(Discount::TYPE_PERCENTAGE);
        $percentage->setValuePercentage(10.0);
        yield '10 % off' => [$percentage];

        $money = new Discount();
        $money->setType(Discount::TYPE_MONEY);
        $money->setValueMoney(BigInteger::of(10000));
        yield '100,00 off' => [$money];
    }

    public function testTheScreenOpensADepositAndRefusesAShareThatIsNotLeft(): void
    {
        $quote = $this->createQuote();
        $this->client->loginUser($this->getUser());

        $this->client->request('GET', '/quotes/view/' . $quote->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('quote-deposit-form', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/invoices/deposit/' . $quote->getId() . '?percentage=33,33');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('33,33 %', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager()->getRepository(Invoice::class)->count([]), 'Nothing is saved until the form is.');

        foreach (['150', '0', 'abc', ''] as $percentage) {
            $this->client->request('GET', '/invoices/deposit/' . $quote->getId() . '?percentage=' . $percentage);
            self::assertTrue($this->client->getResponse()->isRedirect('/quotes/view/' . $quote->getId()), $percentage . ' => ' . $this->client->getResponse()->getStatusCode() . ' ' . $this->client->getResponse()->headers->get('Location'));
        }

        $pending = $this->createQuote(QuoteStatus::Pending);
        $this->client->request('GET', '/invoices/deposit/' . $pending->getId() . '?percentage=30');
        self::assertTrue($this->client->getResponse()->isRedirect('/quotes/view/' . $pending->getId()));
    }

    private function persist(Invoice $invoice, bool $issue): Invoice
    {
        $workflow = self::getContainer()->get('state_machine.invoice');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);

        $workflow->apply($invoice, Graph::TRANSITION_NEW);

        if ($issue) {
            $workflow->apply($invoice, Graph::TRANSITION_ACCEPT);
        }

        $entityManager = $this->entityManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        return $invoice;
    }

    private function createQuote(QuoteStatus $status = QuoteStatus::Accepted, ?Discount $discount = null): Quote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['client' => $client, 'company' => $this->company, 'email' => 'client@example.org']);
        $twenty = TaxFactory::createOne(['company' => $this->company, 'name' => 'TVA 20 %', 'rate' => 20, 'type' => Tax::TYPE_EXCLUSIVE]);
        $ten = TaxFactory::createOne(['company' => $this->company, 'name' => 'TVA 10 %', 'rate' => 10, 'type' => Tax::TYPE_EXCLUSIVE]);

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => $status,
            'archived' => null,
            // The factory draws a discount at random: none here, the figures are checked to the cent.
            'discount' => $discount ?? new Discount(),
            'users' => [$contact],
            'lines' => [
                $this->line('Rénovation de la cuisine', 100000, $twenty, 0),
                $this->line('Peinture', 20000, $twenty, 1),
                $this->line('Fourniture du plan de travail', 50000, $ten, 2),
                new Line()->setDescription('Travaux prévus en mars')->setPrice(BigInteger::of(0))->setQty(1)->setTotal(BigInteger::of(0))->setNote(true)->setPosition(3),
            ],
        ]);
    }

    private function line(string $description, int $price, Tax $tax, int $position): Line
    {
        return new Line()
            ->setDescription($description)
            ->setPrice(BigInteger::of($price))
            ->setQty(1)
            ->setTotal(BigInteger::of($price))
            ->setPosition($position)
            ->addTax(new LineTax()->snapshotFrom($tax));
    }

    private function deposits(): DepositBilling
    {
        $deposits = self::getContainer()->get(DepositBilling::class);
        self::assertInstanceOf(DepositBilling::class, $deposits);

        return $deposits;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
