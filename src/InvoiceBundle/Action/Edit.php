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
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Model\Graph;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use function assert;
use function iterator_to_array;

final readonly class Edit
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private WorkflowInterface $invoiceStateMachine,
        private ManagerRegistry $doctrine,
        private MailerInterface $mailer,
        private TotalCalculator $totalCalculator,
        private InvoiceFormManager $formManager,
    ) {
    }

    /**
     * @return array{recurring: bool, form: FormView, dto: InvoiceFormDTO, invoice: Invoice}|Response
     * @throws MathException
     */
    #[Template('@AugiasInvoice/Default/edit.html.twig')]
    public function __invoke(Request $request, Invoice $invoice): array | Response
    {
        // Asked of the state machine rather than of the status, so the rule
        // lives in one place. This still covers a paid invoice — `edit` does
        // not lead out of that place — and it now also covers an issued one
        // under a regime that holds documents final, where a guard refuses the
        // transition. Hiding the button was never enough on its own: this URL
        // is reachable by hand.
        if (! $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_EDIT)) {
            $blockers = iterator_to_array(
                $this->invoiceStateMachine->buildTransitionBlockerList($invoice, Graph::TRANSITION_EDIT)
            );

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add(
                'warning',
                [] === $blockers ? 'invoice.edit.paid' : $blockers[0]->getMessage(),
            );

            return new RedirectResponse($this->router->generate('_invoices_index'));
        }

        $client = $invoice->getClient();
        if (! $client instanceof Client) {
            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('danger', 'invoice.edit.no_client');

            return new RedirectResponse($this->router->generate('_invoices_index'));
        }

        // Convert Invoice entity to DTO for editing
        $dto = $this->formManager->createDTOFromInvoice($invoice);

        $form = $this->formFactory->create(InvoiceType::class, $dto, [
            'currency' => $client->getCurrency(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $action = $request->request->get('save');

            // Update Invoice from DTO
            $this->formManager->updateInvoiceFromDTO($invoice, $dto);

            // Publish the invoice if the action is 'send' or 'publish'
            if ('send' === $action || 'publish' === $action) {
                $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
            }

            $this->doctrine->getManager()->flush();

            // Send the invoice only if the action is 'send'
            if ('send' === $action) {
                $this->mailer->send(new InvoiceEmail($invoice));
            }

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', 'invoice.edit.success');

            return new RedirectResponse($this->router->generate('_invoices_view', ['id' => $invoice->getId()]));
        }

        if ($form->isSubmitted() && ! $form->isValid()) {
            // Recalculate totals on validation failure
            try {
                $tempInvoice = $this->formManager->createInvoiceFromDTO($dto);
                $this->totalCalculator->calculateTotals($tempInvoice);
                $dto->total = (string) $tempInvoice->getTotal();
                $dto->baseTotal = (string) $tempInvoice->getBaseTotal();
                $dto->tax = (string) $tempInvoice->getTax();
                $dto->disbursementTotal = (string) $tempInvoice->getDisbursementTotal();
            } catch (InvalidArgumentException) {
                // Client data incomplete — keep DTO totals as-is
            }
        }

        return [
            'recurring' => false,
            'form' => $form->createView(),
            'dto' => $dto,
            'invoice' => $invoice,
        ];
    }
}
