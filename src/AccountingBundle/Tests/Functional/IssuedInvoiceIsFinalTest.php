<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Listener\Workflow\IssuedInvoiceIsFinalListener;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;
use function iterator_to_array;

/**
 * Exercises the guard through the real workflow rather than by calling the
 * listener directly: what matters is that the transition is actually refused,
 * and that the ones which should stay open still are.
 */
#[CoversClass(IssuedInvoiceIsFinalListener::class)]
final class IssuedInvoiceIsFinalTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private WorkflowInterface $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = self::getContainer()->get(Registry::class);
        $this->workflow = $registry->get(new Invoice(), 'invoice');
    }

    /**
     * @return iterable<string, array{InvoiceStatus, string}>
     */
    public static function issuedTransitions(): iterable
    {
        yield 'cancel a pending invoice' => [InvoiceStatus::Pending, Graph::TRANSITION_CANCEL];
        yield 'cancel an overdue invoice' => [InvoiceStatus::Overdue, Graph::TRANSITION_CANCEL];
        yield 'edit a pending invoice' => [InvoiceStatus::Pending, Graph::TRANSITION_EDIT];
        yield 'edit an overdue invoice' => [InvoiceStatus::Overdue, Graph::TRANSITION_EDIT];
    }

    #[DataProvider('issuedTransitions')]
    public function testRefusesToUnwindAnIssuedInvoice(InvoiceStatus $status, string $transition): void
    {
        $this->useFrenchRegime();

        $invoice = InvoiceFactory::createOne(['status' => $status]);

        self::assertFalse($this->workflow->can($invoice, $transition));

        $blockers = iterator_to_array($this->workflow->buildTransitionBlockerList($invoice, $transition));

        self::assertCount(1, $blockers);
        self::assertStringContainsString('credit note', $blockers[0]->getMessage());
    }

    /**
     * Nothing has gone to the client yet, so there is nothing to correct.
     */
    public function testLeavesADraftAlone(): void
    {
        $this->useFrenchRegime();

        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Draft]);

        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_CANCEL));
        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_EDIT));
    }

    /**
     * Paying an issued invoice is not a correction and must stay open — the
     * guard only covers the two transitions that unwind a document.
     */
    public function testLeavesTheOrdinaryLifecycleAlone(): void
    {
        $this->useFrenchRegime();

        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Pending]);

        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_PAY));
    }

    /**
     * A company that has not configured its books keeps the transitions it has
     * always had: the rule belongs to the regime, not to the application.
     */
    public function testLeavesAnUnconfiguredCompanyAlone(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::REGIME, '');

        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Pending]);

        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_CANCEL));
        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_EDIT));
    }

    /**
     * Templates ask workflow_can() of invoices that were never persisted, whose
     * company property is typed and still uninitialized. The guard has to
     * tolerate that rather than throw while a page renders.
     */
    public function testToleratesAnInvoiceThatHasNoCompanyYet(): void
    {
        $this->useFrenchRegime();

        $invoice = new Invoice()->setStatus(InvoiceStatus::Pending);

        self::assertTrue($this->workflow->can($invoice, Graph::TRANSITION_CANCEL));
    }

    private function useFrenchRegime(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }
}
