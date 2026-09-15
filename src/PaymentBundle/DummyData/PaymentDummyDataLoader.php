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

namespace Augias\PaymentBundle\DummyData;

use Augias\CoreBundle\DummyData\DummyDataLoaderInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function assert;

#[AsTaggedItem(priority: 60)]
final readonly class PaymentDummyDataLoader implements DummyDataLoaderInterface
{
    private Generator $faker;

    public function __construct(
        private ManagerRegistry $registry,
        private SystemConfig $config,
    ) {
        $this->faker = Factory::create();
    }

    public static function getPriority(): int
    {
        return 60;
    }

    public function load(Company $company): void
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        /** @var InvoiceRepository $invoiceRepository */
        $invoiceRepository = $em->getRepository(Invoice::class);

        $companyCurrency = $this->config->get(SystemConfig::CURRENCY_CONFIG_PATH, $company) ?: 'EUR';

        /** @var Invoice[] $paidInvoices */
        $paidInvoices = $invoiceRepository->findBy(['status' => InvoiceStatus::Paid]);

        foreach ($paidInvoices as $invoice) {
            $client = $invoice->getClient();

            if (null === $client) {
                continue;
            }

            $currencyCode = $client->getCurrencyCode() ?? $companyCurrency;

            $payment = new Payment();
            $payment->setInvoice($invoice)
                ->setClient($client)
                ->setStatus(PaymentStatus::Captured)
                ->setReference($this->faker->numerify('REF-######'))
                ->setCompany($company);
            $payment->setNumber($this->faker->numerify('PAY-######'));
            $payment->setTotalAmount($invoice->getTotal()->toBigInteger()->toInt());
            $payment->setCurrencyCode($currencyCode);
            $payment->setCreated($invoice->getPaidDate());

            $firstContact = $client->getContacts()
                ->first();
            if (false !== $firstContact) {
                $payment->setClientEmail($firstContact->getEmail());
            }

            $paidDate = $invoice->getPaidDate();
            if (null !== $paidDate) {
                $payment->setCompleted(new DateTime($paidDate->format('Y-m-d H:i:s')));
            }

            $em->persist($payment);
        }

        $em->flush();
    }
}
