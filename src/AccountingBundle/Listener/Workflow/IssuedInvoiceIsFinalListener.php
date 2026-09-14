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

namespace Augias\AccountingBundle\Listener\Workflow;

use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Model\Graph;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Contracts\Translation\TranslatorInterface;
use function in_array;

/**
 * Stops an invoice that has gone to the client from being cancelled or edited.
 *
 * Under a regime that holds issued documents final — France does — the invoice
 * is fixed the moment it leaves for the client, and a correction is a credit
 * note that points back at it. Leaving `cancel` and `edit` open alongside the
 * credit note would make them the shorter path to the same result, and the
 * shorter path is the one people take.
 *
 * This lives in AccountingBundle rather than InvoiceBundle on purpose: the rule
 * belongs to the regime, and this bundle already depends on the invoice one.
 * The reverse would be a circular dependency for a rule that is not an
 * invoicing concern at all.
 *
 * A company with no regime configured, or one that answers false, keeps the
 * transitions it has always had.
 *
 * @see \Augias\AccountingBundle\Tests\Listener\Workflow\IssuedInvoiceIsFinalListenerTest
 */
final readonly class IssuedInvoiceIsFinalListener
{
    /**
     * The places an invoice reaches only by being issued. `accept` is what puts
     * it there, and there is no way back to a draft that does not go through
     * one of the two transitions this listener guards.
     */
    private const array ISSUED = [InvoiceStatus::Pending, InvoiceStatus::Overdue];

    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param GuardEvent<Invoice> $event
     */
    #[AsEventListener('workflow.invoice.guard.' . Graph::TRANSITION_CANCEL)]
    public function onGuardCancel(GuardEvent $event): void
    {
        $this->blockWhenIssued($event, 'accounting.invoice.cancel_blocked');
    }

    /**
     * @param GuardEvent<Invoice> $event
     */
    #[AsEventListener('workflow.invoice.guard.' . Graph::TRANSITION_EDIT)]
    public function onGuardEdit(GuardEvent $event): void
    {
        $this->blockWhenIssued($event, 'accounting.invoice.edit_blocked');
    }

    /**
     * @param GuardEvent<Invoice> $event
     */
    private function blockWhenIssued(GuardEvent $event, string $messageKey): void
    {
        $invoice = $event->getSubject();

        if (! $invoice instanceof Invoice || ! in_array($invoice->getStatus(), self::ISSUED, true)) {
            return;
        }

        // The guard runs wherever a template asks workflow_can(), and that
        // includes invoices that were never persisted, whose company property
        // is typed and still uninitialized. getCompanyId() is the accessor that
        // probes it safely; getCompany() would throw.
        if (null === $invoice->getCompanyId()) {
            return;
        }

        $profile = $this->profileProvider->forCompany($invoice->getCompany());
        $regime = $this->registry->forProfile($profile);

        if (null === $regime || ! $regime->issuedDocumentsAreFinal()) {
            return;
        }

        // The message names the way forward. A blocked transition with no
        // alternative reads as a bug rather than a rule.
        $event->addTransitionBlocker(
            TransitionBlocker::createUnknown($this->translator->trans($messageKey)),
        );
    }
}
