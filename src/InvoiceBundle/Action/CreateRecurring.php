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

namespace Augias\InvoiceBundle\Action;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Entity\RecurringInvoiceLine;
use Augias\InvoiceBundle\Form\Type\RecurringInvoiceType;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\SaasBundle\Feature\Feature;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Workflow\WorkflowInterface;
use function assert;

/**
 * @see \Augias\InvoiceBundle\Tests\Action\CreateRecurringTest
 */
final class CreateRecurring extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly WorkflowInterface $recurringInvoiceStateMachine,
        private readonly RouterInterface $router,
        private readonly ManagerRegistry $doctrine,
        private readonly TotalCalculator $totalCalculator,
        private readonly FeatureGate $featureGate,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws MathException
     */
    public function __invoke(Request $request, ?Client $client = null): Response
    {
        if (! $this->featureGate->isEnabled(Feature::RecurringInvoices->value)) {
            return $this->render('@AugiasInvoice/Default/recurring_gated.html.twig');
        }

        if (! $this->featureGate->canUse(
            Feature::InvoicesPerMonth->value,
            $this->invoiceRepository->countCreatedInMonth($this->clock->now()),
        )) {
            return $this->render('@AugiasInvoice/Default/invoice_gated.html.twig');
        }

        $totalClientsCount = $this->clientRepository->getTotalClients();
        if (0 === $totalClientsCount) {
            return $this->render('@AugiasInvoice/Default/empty_clients.html.twig');
        }

        if (1 === $totalClientsCount && ! $client instanceof Client) {
            $client = $this->clientRepository->findOneBy([]);
        }

        $invoice = new RecurringInvoice();

        // The first line waits for the client, see ManagesNoteLines.
        if ($client instanceof Client) {
            $invoice->addLine(new RecurringInvoiceLine());
        }
        $invoice->setClient($client);

        // Auto-select all client contacts
        if ($client instanceof Client) {
            foreach ($client->getContacts() as $contact) {
                $invoice->addUser($contact);
            }
        }

        $formOptions = $client instanceof Client ? ['currency' => $client->getCurrency()] : [];
        $form = $this->createForm(RecurringInvoiceType::class, $invoice, $formOptions);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $action = $request->request->get('save');

            if (! $invoice->getId() instanceof Ulid) {
                $this->recurringInvoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
            }

            if ('publish' === $action && $this->recurringInvoiceStateMachine->can($invoice, Graph::TRANSITION_ACTIVATE)) {
                $this->recurringInvoiceStateMachine->apply($invoice, Graph::TRANSITION_ACTIVATE);
            }

            $entityManager = $this->doctrine->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', 'invoice.create.success');

            return new RedirectResponse($this->router->generate('_invoices_view_recurring', ['id' => $invoice->getId()]));
        }

        if ($form->isSubmitted() && ! $form->isValid()) {
            $this->totalCalculator->calculateTotals($invoice);
        }

        return $this->render('@AugiasInvoice/Default/create.html.twig', [
            'invoice' => $invoice,
            'form' => $form,
            'recurring' => true,
        ]);
    }
}
