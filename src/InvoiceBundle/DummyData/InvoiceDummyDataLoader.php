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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\DummyData\DummyDataLoaderInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Repository\TaxRepository;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function array_rand;
use function assert;
use function random_int;

#[AsTaggedItem(priority: 70)]
final readonly class InvoiceDummyDataLoader implements DummyDataLoaderInterface
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
        return 70;
    }

    public function load(Company $company): void
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        /** @var ClientRepository $clientRepository */
        $clientRepository = $em->getRepository(Client::class);

        /** @var TaxRepository $taxRepository */
        $taxRepository = $em->getRepository(Tax::class);

        /** @var Client[] $clients */
        $clients = $clientRepository->findAll();

        /** @var Tax[] $taxes */
        $taxes = $taxRepository->findAll();

        $statuses = [
            InvoiceStatus::Draft,
            InvoiceStatus::Pending,
            InvoiceStatus::Paid,
            InvoiceStatus::Overdue,
        ];

        foreach ($clients as $client) {
            for ($i = 0; $i < 5; ++$i) {
                $invoice = new Invoice();
                $invoice->setCompany($company);
                $invoice->setClient($client);

                $status = $statuses[array_rand($statuses)];
                $invoice->setStatus($status);

                // A fifth of them inside the last few weeks. Spread evenly over a
                // year, a demo set has almost nothing in the current month, so
                // every "this month" figure on the dashboard reads zero and the
                // running accounting period looks empty — which is exactly what
                // someone opening a demo wants to see working.
                $daysAgo = $this->faker->boolean(20) ? random_int(1, 25) : random_int(1, 365);
                $invoiceDate = new DateTimeImmutable('-' . $daysAgo . ' days');
                $invoice->setInvoiceDate($invoiceDate);

                if ($status === InvoiceStatus::Pending || $status === InvoiceStatus::Overdue) {
                    $invoice->setDue(new DateTimeImmutable($invoiceDate->format('Y-m-d') . ' +30 days'));
                }

                if ($status === InvoiceStatus::Paid) {
                    $paidDaysAgo = random_int(1, $daysAgo);
                    $invoice->setPaidDate(new DateTimeImmutable('-' . $paidDaysAgo . ' days'));
                }

                if ($this->faker->boolean(60)) {
                    $invoice->setNotes($this->faker->sentence(10));
                }

                if ($this->faker->boolean(40)) {
                    $invoice->setTerms($this->faker->sentence(15));
                }

                $lineCount = random_int(2, 4);
                $baseTotal = BigDecimal::zero();
                $taxTotal = BigDecimal::zero();

                for ($j = 0; $j < $lineCount; ++$j) {
                    $price = random_int(500, 50000);
                    $qty = random_int(1, 10);

                    $line = new Line();
                    $line->setDescription($this->faker->sentence(5))
                        ->setPrice($price)
                        ->setQty($qty)
                        ->setCompany($company);

                    $lineTotal = BigDecimal::of($price)->multipliedBy($qty);
                    $baseTotal = $baseTotal->plus($lineTotal);

                    if ([] !== $taxes && $this->faker->boolean(50)) {
                        $tax = $taxes[array_rand($taxes)];
                        $lineTax = new LineTax();
                        $lineTax->snapshotFrom($tax);
                        $line->addTax($lineTax);

                        if ($tax->getType() === Tax::TYPE_EXCLUSIVE && $tax->getRate() > 0.0) {
                            $taxAmount = $lineTotal->multipliedBy((string) $tax->getRate())
                                ->dividedBy(100, 0, RoundingMode::HalfUp);
                            $taxTotal = $taxTotal->plus($taxAmount);
                        }
                    }

                    $invoice->addLine($line);
                }

                $total = $baseTotal->plus($taxTotal);

                $invoice->setBaseTotal($baseTotal)
                    ->setTax($taxTotal)
                    ->setTotal($total);

                if ($status === InvoiceStatus::Paid) {
                    $invoice->setBalance(BigInteger::zero());
                } else {
                    $invoice->setBalance($total);
                }

                $firstContact = $client->getContacts()
                    ->first();
                if (false !== $firstContact) {
                    $invoice->addUser($firstContact);
                }

                $invoice->setInvoiceId($this->billingIdGenerator->generate($invoice, ['field' => 'invoiceId']));

                $em->persist($invoice);
            }

            $em->flush();
        }
    }
}
