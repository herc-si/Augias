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

namespace Augias\InvoiceBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Listener\NumberOnFinaliseListener;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Invoices are numbered when finalised, credit notes when issued.
 */
#[CoversClass(NumberOnFinaliseListener::class)]
final class NumberOnFinaliseTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    protected function setUp(): void
    {
        parent::setUp();

        // Bare numbers, to read the series at a glance.
        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);
        $config->set('invoice/id_generation/strategy', 'auto_increment');
        $config->set('invoice/id_generation/id_prefix', '');
        $config->set('invoice/id_generation/id_suffix', '');
        $config->set('credit_note/id_generation/strategy', 'auto_increment');
        $config->set('credit_note/id_generation/id_prefix', '');
        $config->set('credit_note/id_generation/id_suffix', '');
    }

    /**
     * Rémi's case (08/10/2026): a draft left aside, another invoice finalised,
     * the draft cancelled. The draft never had a number, so the series has no
     * gap, and the next invoice follows straight on.
     */
    public function testADraftLeftAsideLeavesNoGap(): void
    {
        $leftAside = $this->draft();
        self::assertSame('', $leftAside->getInvoiceId());

        $finalised = $this->draft();
        $this->invoices()->apply($finalised, Graph::TRANSITION_ACCEPT);
        $this->entityManager()->flush();
        self::assertSame('1', $finalised->getInvoiceId());

        $this->invoices()->apply($leftAside, Graph::TRANSITION_CANCEL);
        $this->entityManager()->flush();
        self::assertSame('', $leftAside->getInvoiceId());

        $next = $this->draft();
        $this->invoices()->apply($next, Graph::TRANSITION_ACCEPT);
        $this->entityManager()->flush();
        self::assertSame('2', $next->getInvoiceId());
    }

    public function testAnInvoiceThatHasANumberKeepsIt(): void
    {
        $invoice = $this->draft();
        $invoice->setInvoiceId('FACT-ANCIENNE-7');

        $this->invoices()->apply($invoice, Graph::TRANSITION_ACCEPT);

        self::assertSame('FACT-ANCIENNE-7', $invoice->getInvoiceId());
    }

    public function testANumberIsUsedOnceInACompany(): void
    {
        InvoiceFactory::createOne(['company' => $this->company, 'status' => InvoiceStatus::Pending, 'invoiceId' => '42']);

        $this->expectException(UniqueConstraintViolationException::class);

        InvoiceFactory::createOne(['company' => $this->company, 'status' => InvoiceStatus::Pending, 'invoiceId' => '42']);
    }

    public function testACreditNoteIsNumberedWhenIssued(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'status' => CreditNoteStatus::Draft,
            'creditNoteId' => '',
        ]);
        self::assertSame('', $creditNote->getCreditNoteId());

        $workflow = self::getContainer()->get('state_machine.credit_note');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);

        self::assertSame('1', $creditNote->getCreditNoteId());
        self::assertSame(CreditNoteStatus::Issued, $creditNote->getStatus());
    }

    private function draft(): Invoice
    {
        return InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']),
            'status' => InvoiceStatus::Draft,
            'archived' => null,
        ]);
    }

    private function invoices(): WorkflowInterface
    {
        $workflow = self::getContainer()->get('state_machine.invoice');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);

        return $workflow;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
