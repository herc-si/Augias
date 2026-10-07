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

namespace Augias\ElectronicInvoicingBundle\Action;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Form\Type\ReceiptResponseType;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManagerInterface;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function assert;
use function is_string;
use function parse_url;
use function str_starts_with;

/**
 * Accept a received invoice, dispute it or refuse it with a reason — sent back through
 * the platform it came from, so the supplier learns the outcome.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Action\RespondToIncomingInvoiceTest
 */
final readonly class RespondToIncomingInvoice
{
    public function __construct(
        private ElectronicInvoiceReceiptRepository $receiptRepository,
        private ElectronicInvoiceReceiptManagerInterface $receiptManager,
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{receipt: ElectronicInvoiceReceipt, form: FormView|null}|RedirectResponse
     */
    #[Template('@AugiasElectronicInvoicing/Incoming/respond.html.twig')]
    public function __invoke(string $id, Request $request): array | RedirectResponse
    {
        $receipt = $this->receipt($id);
        $back = $this->back($request);

        if (! $this->receiptManager->canRespond($receipt)) {
            return ['receipt' => $receipt, 'form' => null, 'back' => $back];
        }

        $form = $this->formFactory->create(ReceiptResponseType::class, null, ['previous' => $receipt->getResponse()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{response: ReceiptResponse, reason: ResponseReason|null, comment: string|null} $data */
            $data = $form->getData();

            try {
                $this->receiptManager->respond($receipt, $data['response'], $data['reason'], $data['comment']);
            } catch (RuntimeException $e) {
                $form->addError(new FormError($this->translator->trans($e->getMessage())));

                return ['receipt' => $receipt, 'form' => $form->createView(), 'back' => $back];
            }

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', match ($data['response']) {
                ReceiptResponse::Accepted => 'einvoicing.response.flash.accepted',
                ReceiptResponse::Disputed => 'einvoicing.response.flash.disputed',
                ReceiptResponse::Refused => 'einvoicing.response.flash.refused',
            });

            return new RedirectResponse($back);
        }

        return ['receipt' => $receipt, 'form' => $form->createView(), 'back' => $back];
    }

    /**
     * Where the answer was asked from — the purchase it became, or the inbox
     * — within the application only.
     */
    private function back(Request $request): string
    {
        $path = $request->query->get('back');

        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && null === parse_url($path, PHP_URL_HOST)) {
            return $path;
        }

        return $this->router->generate('_einvoicing_incoming');
    }

    private function receipt(string $id): ElectronicInvoiceReceipt
    {
        try {
            $receipt = $this->receiptRepository->find(Ulid::fromString($id));
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException();
        }

        if (! $receipt instanceof ElectronicInvoiceReceipt) {
            throw new NotFoundHttpException();
        }

        return $receipt;
    }
}
