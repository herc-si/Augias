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

namespace Augias\InvoiceBundle\DummyData;

use Augias\CoreBundle\DummyData\DummyDataLoaderInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function array_rand;
use function assert;
use function random_int;

/**
 * Runs after the invoice loader (70) and the payment loader (60): a credit note
 * mirrors an invoice that already exists, so there is nothing to credit until
 * those have run.
 *
 * Amounts are positive, as they are everywhere else in the application — a credit
 * note carries what is owed back, and the direction is carried by the document
 * being a credit note, not by a sign.
 */
#[AsTaggedItem(priority: 50)]
final readonly class CreditNoteDummyDataLoader implements DummyDataLoaderInterface
{
    private Generator $faker;

    public function __construct(
        private ManagerRegistry $registry,
        private BillingIdGenerator $billingIdGenerator,
        private CreditNoteAllocator $allocator,
    ) {
        $this->faker = Factory::create();
    }

    public static function getPriority(): int
    {
        return 50;
    }

    public function load(Company $company): void
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        /** @var InvoiceRepository $invoiceRepository */
        $invoiceRepository = $em->getRepository(Invoice::class);

        /** @var Invoice[] $invoices */
        $invoices = $invoiceRepository->findAll();

        if ([] === $invoices) {
            return;
        }

        $reasons = CreditReason::cases();

        // Weighted on purpose: most credit notes in a real set have been handed
        // over, a few are still being prepared. Settled is not in the list —
        // a credit note becomes settled by being used up, and the allocator is
        // what says so. Setting the status by hand would produce a document
        // that claims to be spent with an empty journal underneath it.
        $statuses = [
            CreditNoteStatus::Issued,
            CreditNoteStatus::Issued,
            CreditNoteStatus::Issued,
            CreditNoteStatus::Draft,
        ];

        foreach ($invoices as $invoice) {
            // Not every invoice earns a credit note, or the set stops looking
            // like anyone's books.
            if (! $this->faker->boolean(20)) {
                continue;
            }

            $client = $invoice->getClient();

            if (null === $client) {
                continue;
            }

            $creditNote = new CreditNote();
            $creditNote->setCompany($company);
            $creditNote->setClient($client);
            $creditNote->setCreditedInvoice($invoice);
            $creditNote->setReason($reasons[array_rand($reasons)]);

            $status = $statuses[array_rand($statuses)];
            $creditNote->setStatus($status);

            // Dated after the invoice it credits: crediting a document before it
            // exists is not a state the books should ever be shown in.
            $invoiceDate = $invoice->getInvoiceDate();
            $creditNoteDate = (
                $invoiceDate instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($invoiceDate)
                : new DateTimeImmutable('-30 days')
            )->modify('+' . random_int(1, 45) . ' days');

            if ($creditNoteDate > new DateTimeImmutable()) {
                $creditNoteDate = new DateTimeImmutable();
            }

            $creditNote->setCreditNoteDate($creditNoteDate);

            if ($status !== CreditNoteStatus::Draft) {
                $creditNote->setIssuedAt($creditNoteDate);
            }

            $lineCount = random_int(1, 2);
            $baseTotal = BigDecimal::zero();

            for ($i = 0; $i < $lineCount; ++$i) {
                $price = random_int(500, 20000);
                $qty = random_int(1, 3);

                $line = new CreditNoteLine();
                $line->setDescription($this->faker->sentence(5))
                    ->setPrice($price)
                    ->setQty($qty)
                    ->setCompany($company);

                $baseTotal = $baseTotal->plus(BigDecimal::of($price)->multipliedBy($qty));

                $creditNote->addLine($line);
            }

            $creditNote->setBaseTotal($baseTotal)
                ->setTax(BigDecimal::zero())
                ->setTotal($baseTotal);

            $firstContact = $client->getContacts()
                ->first();

            if (false !== $firstContact) {
                $creditNote->addUser($firstContact);
            }

            $creditNote->setCreditNoteId(
                $this->billingIdGenerator->generate($creditNote, ['field' => 'creditNoteId'])
            );

            $em->persist($creditNote);
            $em->flush();

            $this->useUpSomeOf($creditNote, $invoice, $status);
        }
    }

    /**
     * Half of the issued credit notes have been used, because a set where every
     * credit note is still outstanding shows none of the machinery underneath:
     * no allocation journal, no client balance coming back down, and — for a
     * refund, which is money leaving — no entry in the books.
     *
     * Offsets are set against the invoice the credit note was raised on, which
     * is the only invoice it is allowed to touch. Refunds take no invoice.
     */
    private function useUpSomeOf(CreditNote $creditNote, Invoice $invoice, CreditNoteStatus $status): void
    {
        if (CreditNoteStatus::Issued !== $status || ! $this->faker->boolean(50)) {
            return;
        }

        $total = $creditNote->getTotal()->toBigDecimal();

        // In full, so the credit note settles, or a slice of it, so the set has
        // partly-used ones too. A slice is rounded to whole units: a fraction of
        // a cent is not an amount anyone can allocate.
        $amount = $this->faker->boolean(60) ? $total : $total->multipliedBy(4)->dividedBy(10, 0, RoundingMode::Down);

        if ($amount->isNegativeOrZero()) {
            return;
        }

        $kind = $this->faker->boolean(70) ? AllocationKind::Offset : AllocationKind::Refund;

        // Recent, and never before the credit note existed: an allocation dated
        // into a period the books have already shut is refused, as it should be.
        $on = new DateTimeImmutable('-' . random_int(0, 20) . ' days');
        $creditNoteDate = $creditNote->getCreditNoteDate();

        if ($on < $creditNoteDate) {
            $on = $creditNoteDate;
        }

        try {
            $this->allocator->allocate(
                $creditNote,
                $kind,
                $amount,
                AllocationKind::Offset === $kind ? $invoice : null,
                $on,
            );
        } catch (AllocationException) {
            // A demo set is not worth failing a load over: the books may be shut
            // past this date, or the rules may have moved on. The credit note
            // stays issued and unused, which is a state the set already has.
        }
    }
}
