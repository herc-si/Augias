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
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\SaasBundle\Feature\Feature;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Workflow\WorkflowInterface;
use function assert;

final class Create extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly WorkflowInterface $invoiceStateMachine,
        private readonly RouterInterface $router,
        private readonly ManagerRegistry $doctrine,
        private readonly MailerInterface $mailer,
        private readonly TotalCalculator $totalCalculator,
        private readonly InvoiceFormManager $formManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly FeatureGate $featureGate,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws MathException
     */
    public function __invoke(Request $request, ?Client $client = null): Response
    {
        if (! $this->featureGate->canUse(
            Feature::InvoicesPerMonth->value,
            $this->invoiceRepository->countCreatedInMonth($this->clock->now()),
        )) {
            return $this->render('@AugiasInvoice/Default/invoice_gated.html.twig');
        }

        $totalClientsCount = $this->clientRepository->getTotalClients();
        if (1 === $totalClientsCount && ! $client instanceof Client) {
            $client = $this->clientRepository->findOneBy([]);
        }

        // Create DTO instead of entity
        $dto = new InvoiceFormDTO();

        // Set client mode based on client count
        $dto->clientMode = $totalClientsCount > 0 ? InvoiceClientMode::Existing : InvoiceClientMode::NewClient;

        // Set client if provided
        $dto->client = $client;

        // Set default invoice date to today
        $dto->invoiceDate = CarbonImmutable::now();

        // Add one empty line item by default
        $dto->lines->add(new Line());

        // Contact auto-selection is handled by the LiveComponent's initializeContacts() hook

        $formOptions = $client instanceof Client ? ['currency' => $client->getCurrency()] : [];

        $form = $this->createForm(InvoiceType::class, $dto, $formOptions);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $action = $request->request->get('save');

            // Convert DTO to Invoice entity
            // If clientMode=New, manager creates unpersisted client
            // Client will be persisted via cascade when invoice is saved
            $invoice = $this->formManager->createInvoiceFromDTO($dto);

            if (! $invoice->getId() instanceof Ulid) {
                $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
            }

            // Publish the invoice if the action is 'send' or 'publish'
            if ('send' === $action || 'publish' === $action) {
                $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
            }

            $entityManager = $this->doctrine->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();

            // Send the invoice only if the action is 'send'
            if ('send' === $action) {
                $this->mailer->send(new InvoiceEmail($invoice));
            }

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', 'invoice.create.success');

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

        return $this->render('@AugiasInvoice/Default/create.html.twig', [
            'dto' => $dto,
            'form' => $form,
            'recurring' => false,
        ]);
    }
}
