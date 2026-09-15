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
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Brick\Math\BigDecimal;
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
        // over, a few are still being prepared, and a few have been used up.
        $statuses = [
            CreditNoteStatus::Issued,
            CreditNoteStatus::Issued,
            CreditNoteStatus::Issued,
            CreditNoteStatus::Draft,
            CreditNoteStatus::Settled,
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
        }
    }
}
