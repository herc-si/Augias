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

namespace Augias\InvoiceBundle\Tests\Dashboard;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\Discount;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Dashboard\CreditNotesTotalWidget;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function uniqid;

#[CoversClass(CreditNotesTotalWidget::class)]
final class CreditNotesTotalWidgetTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testStaysHiddenUntilOneHasBeenHandedOver(): void
    {
        self::assertFalse($this->widget()->supports());

        $this->creditNote(50_000, CreditNoteStatus::Draft);

        self::assertFalse($this->widget()->supports(), 'A draft has not been handed to anyone yet.');

        $this->creditNote(20_000);

        self::assertTrue($this->widget()->supports());
    }

    public function testSumsWhatHasBeenCredited(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(20_000);

        self::assertSame('70000', (string) $this->widget()->getData()['issued']['EUR']);
    }

    /**
     * The difference between this tile and the card below it. A credit note the
     * client has used up is still one the company issued, so the lifetime
     * figure must not fall when it settles — the card's "still owed" is the
     * number that falls.
     */
    public function testACreditNoteThatHasBeenUsedUpStillCounts(): void
    {
        $creditNote = $this->creditNote(50_000);
        $creditNote->setStatus(CreditNoteStatus::Settled);
        $this->entityManager->flush();

        self::assertSame('50000', (string) $this->widget()->getData()['issued']['EUR']);
    }

    public function testIgnoresDrafts(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(20_000, CreditNoteStatus::Draft);

        self::assertSame('50000', (string) $this->widget()->getData()['issued']['EUR']);
    }

    /**
     * Only a refund is money leaving. An offset costs nothing on its own — the
     * client simply pays less next time — so it must not appear under a line
     * that reads "refunded".
     */
    public function testShowsOnlyWhatWasActuallyPaidBack(): void
    {
        $creditNote = $this->creditNote(50_000);
        $this->allocate($creditNote, 20_000, AllocationKind::Refund);
        $this->allocate($creditNote, 10_000, AllocationKind::Offset);

        $data = $this->widget()->getData();

        self::assertSame('50000', (string) $data['issued']['EUR']);
        self::assertSame('20000', (string) $data['refunded']['EUR']);
    }

    /**
     * A refund line reading zero is the ordinary case, and a stat tile that
     * states the ordinary case teaches nothing.
     */
    public function testSaysNothingAboutRefundsWhenThereWereNone(): void
    {
        $this->creditNote(50_000);

        self::assertSame([], $this->widget()->getData()['refunded']);
    }

    /**
     * The reason this is two queries rather than one: joining the allocations
     * multiplies each credit note's row by the number of times it was drawn on,
     * and the issued total comes out doubled or tripled.
     */
    public function testACreditNoteDrawnOnSeveralTimesIsNotCountedSeveralTimes(): void
    {
        $creditNote = $this->creditNote(50_000);

        $this->allocate($creditNote, 10_000, AllocationKind::Refund);
        $this->allocate($creditNote, 10_000, AllocationKind::Refund);
        $this->allocate($creditNote, 10_000, AllocationKind::Refund);

        $data = $this->widget()->getData();

        self::assertSame('50000', (string) $data['issued']['EUR']);
        self::assertSame('30000', (string) $data['refunded']['EUR']);
    }

    /**
     * Two currencies are two answers, never one: a credit note is settled in
     * the currency it was raised in.
     */
    public function testKeepsCurrenciesApart(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(30_000, CreditNoteStatus::Issued, 'GBP');

        $issued = $this->widget()->getData()['issued'];

        self::assertSame('50000', (string) $issued['EUR']);
        self::assertSame('30000', (string) $issued['GBP']);
    }

    /**
     * The template is rendered rather than inspected, and with data from the
     * widget rather than invented: a stat tile is a handful of macro calls, and
     * every way it can break — an import missing from the file, a variable the
     * widget stopped providing, a macro whose signature moved — breaks at
     * render time and nowhere earlier.
     */
    public function testTheTileRenders(): void
    {
        $creditNote = $this->creditNote(50_000);
        $this->allocate($creditNote, 20_000, AllocationKind::Refund);

        $widget = $this->widget();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render($widget->getTemplate(), $widget->getData());

        self::assertStringContainsString('500.00', $html);
        self::assertStringContainsString('200.00', $html);
        self::assertStringContainsString('stat-subvalue', $html);
    }

    private function widget(): CreditNotesTotalWidget
    {
        $widget = self::getContainer()->get(CreditNotesTotalWidget::class);
        self::assertInstanceOf(CreditNotesTotalWidget::class, $widget);

        return $widget;
    }

    private function creditNote(
        int $amount,
        CreditNoteStatus $status = CreditNoteStatus::Issued,
        string $currency = 'EUR',
    ): CreditNote {
        $client = ClientFactory::createOne(['currencyCode' => $currency]);

        $creditNote = new CreditNote();
        $creditNote->setClient($this->entityManager->find(Client::class, $client->getId()));
        $creditNote->setCreditNoteId('AV-' . uniqid());
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(CarbonImmutable::now());
        $creditNote->setStatus($status);
        $creditNote->setDiscount(new Discount());
        $creditNote->setCompany($this->entityManager->find(Company::class, $this->company->getId()));
        $creditNote->addLine(
            new CreditNoteLine()
                ->setDescription('Credited')
                ->setPrice($amount)
                ->setQty(1)
                ->updateTotal(),
        );

        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        return $creditNote;
    }

    /**
     * Written directly rather than through the allocator, which would settle
     * the credit note once it is used up and move the status under the test.
     */
    private function allocate(CreditNote $creditNote, int $amount, AllocationKind $kind): void
    {
        $allocation = new CreditNoteAllocation()
            ->setCreditNote($creditNote)
            ->setKind($kind)
            ->setAmount($amount)
            ->setAllocatedOn(new DateTimeImmutable('today'));

        $allocation->setCompany($creditNote->getCompany());

        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
    }
}
