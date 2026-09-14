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

namespace Augias\InvoiceBundle\Action\CreditNote;

use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\InvoiceBundle\DTO\CreditNoteAllocationDTO;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Form\Type\CreditNoteAllocationType;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Mpdf\MpdfException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use function assert;
use function sprintf;

/**
 * @see \Augias\InvoiceBundle\Tests\Action\CreditNote\ViewTest
 */
final readonly class View
{
    public function __construct(
        private Generator $pdfGenerator,
        private Environment $twig,
        private CreditNoteAllocator $allocator,
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
    ) {
    }

    /**
     * @return array{creditNote: CreditNote, remaining: BigNumber, allocationForm: FormView|null}|Response
     * @throws LoaderError
     * @throws MathException
     * @throws MpdfException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    #[Template('@AugiasInvoice/CreditNote/view.html.twig')]
    public function __invoke(Request $request, CreditNote $creditNote): array | Response
    {
        // No per-company template override yet, unlike invoices and quotes: the
        // resolver is keyed on those two document types, and a credit note has
        // no custom template to resolve to.
        if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
            return new PdfResponse(
                $this->pdfGenerator->generate(
                    $this->twig->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote]),
                ),
                sprintf('credit_note_%s.pdf', $creditNote->getCreditNoteId()),
            );
        }

        $remaining = $this->allocator->remaining($creditNote);

        return [
            'creditNote' => $creditNote,
            'remaining' => $remaining,
            // Offered only while there is something left to settle, and only on
            // a document the client actually holds.
            'allocationForm' => $creditNote->isIssued() && $remaining->isPositive()
                ? $this->allocationForm($creditNote)->createView()
                : null,
        ];
    }

    /**
     * @return FormInterface<CreditNoteAllocationDTO>
     */
    private function allocationForm(CreditNote $creditNote): FormInterface
    {
        $dto = new CreditNoteAllocationDTO();
        $dto->allocatedOn = CarbonImmutable::now();

        $clientId = $creditNote->getClient()->getId();
        assert($clientId instanceof Ulid);

        return $this->formFactory->create(CreditNoteAllocationType::class, $dto, [
            'currency' => $creditNote->getClient()->getCurrency(),
            'client_id' => $clientId,
            'action' => $this->router->generate('_credit_notes_allocate', ['id' => $creditNote->getId()]),
        ]);
    }
}
