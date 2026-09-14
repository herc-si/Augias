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
use Augias\InvoiceBundle\Dashboard\CreditNotesWidget;
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
use function uniqid;

#[CoversClass(CreditNotesWidget::class)]
final class CreditNotesWidgetTest extends KernelTestCase
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

    /**
     * A business that has never credited anyone does not need a card telling it
     * that credit notes exist.
     */
    public function testStaysHiddenUntilOneExists(): void
    {
        self::assertFalse($this->widget()->supports());

        $this->creditNote(50_000);

        self::assertTrue($this->widget()->supports());
    }

    public function testOwesWhatHasBeenIssuedAndNotYetSettled(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(20_000);

        self::assertSame('70000', (string) $this->widget()->getData()['owed']['EUR']);
    }

    /**
     * A draft owes nothing: it has not been handed over.
     */
    public function testIgnoresDrafts(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(20_000, CreditNoteStatus::Draft);

        $data = $this->widget()->getData();

        self::assertSame('50000', (string) $data['owed']['EUR']);
        self::assertSame(1, $data['draftTotal']);
    }

    public function testTakesOffWhatHasAlreadyBeenAllocated(): void
    {
        $creditNote = $this->creditNote(50_000);
        $this->allocate($creditNote, 20_000);

        self::assertSame('30000', (string) $this->widget()->getData()['owed']['EUR']);
    }

    /**
     * The reason this is two queries rather than one.
     *
     * Joining the allocations to the credit notes multiplies each credit note's
     * row by the number of times it was drawn on: with three allocations the
     * issued total would be counted three times, and the card would claim the
     * company owes triple what it does.
     */
    public function testACreditNoteDrawnOnSeveralTimesIsNotCountedSeveralTimes(): void
    {
        $creditNote = $this->creditNote(50_000);

        $this->allocate($creditNote, 10_000);
        $this->allocate($creditNote, 10_000);
        $this->allocate($creditNote, 10_000);

        self::assertSame('20000', (string) $this->widget()->getData()['owed']['EUR']);
    }

    /**
     * Two currencies are two answers to "what do I owe", never one. An exchange
     * rate invented here is one the books never recorded.
     */
    public function testKeepsCurrenciesApart(): void
    {
        $this->creditNote(50_000);
        $this->creditNote(30_000, CreditNoteStatus::Issued, 'GBP');

        $owed = $this->widget()->getData()['owed'];

        self::assertSame('50000', (string) $owed['EUR']);
        self::assertSame('30000', (string) $owed['GBP']);
    }

    public function testACreditNoteUsedUpDropsOffTheCard(): void
    {
        $creditNote = $this->creditNote(50_000);
        $this->allocate($creditNote, 50_000);

        self::assertArrayNotHasKey('EUR', $this->widget()->getData()['owed']);
    }

    private function widget(): CreditNotesWidget
    {
        $widget = self::getContainer()->get(CreditNotesWidget::class);
        self::assertInstanceOf(CreditNotesWidget::class, $widget);

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
     * Written directly rather than through the allocator: this is about what
     * the widget reads back, and the allocator settles a credit note once it is
     * used up, which would take it out of the "issued" set the card sums.
     */
    private function allocate(CreditNote $creditNote, int $amount): void
    {
        $allocation = new CreditNoteAllocation()
            ->setCreditNote($creditNote)
            ->setKind(AllocationKind::Refund)
            ->setAmount($amount)
            ->setAllocatedOn(new DateTimeImmutable('today'));

        $allocation->setCompany($creditNote->getCompany());

        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
    }
}
