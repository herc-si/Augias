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

namespace Augias\CoreBundle\Action;

use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Mpdf\MpdfException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ViewBilling
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RouterInterface $router,
        private readonly CompanySelector $companySelector,
        private readonly Generator $pdfGenerator,
        private readonly Environment $twig,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly BillingTemplateResolver $templateResolver,
        private readonly DocumentActivityRecorder $activity,
    ) {
    }

    /**
     * View a quote if not logged in.
     *
     * @return array{quote: Quote, title: string, template: string}|Response
     * @throws InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|NotFoundHttpException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    #[Template('@AugiasCore/View/quote.html.twig')]
    public function quoteAction(Request $request, string $uuid): array | Response
    {
        $options = [
            'repository' => Quote::class,
            'route' => '_quotes_view',
            'template' => '@AugiasQuote/quote_template.html.twig',
            'uuid' => $uuid,
            'entity' => 'quote',
            'pdfTemplate' => '@AugiasQuote/Pdf/quote.html.twig',
        ];

        return $this->createResponse($request, $options);
    }

    /**
     * View a invoice if not logged in.
     *
     * @return array{invoice: Invoice, title: string, template: string}|Response
     * @throws InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|NotFoundHttpException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    #[Template('@AugiasCore/View/invoice.html.twig')]
    public function invoiceAction(Request $request, string $uuid): array | Response
    {
        $options = [
            'repository' => Invoice::class,
            'route' => '_invoices_view',
            'template' => '@AugiasInvoice/external_invoice_view.html.twig',
            'uuid' => $uuid,
            'entity' => 'invoice',
            'pdfTemplate' => '@AugiasInvoice/Pdf/invoice.html.twig',
        ];

        return $this->createResponse($request, $options);
    }

    /**
     * @param array{"repository": class-string, "route": string, "template": string, "uuid": string, "entity": string, "pdfTemplate": string} $options
     * @return array<string, mixed>|Response
     * @throws NotFoundHttpException|InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    private function createResponse(Request $request, array $options): array | Response
    {
        $repository = $this->registry->getRepository($options['repository']);

        $entity = $repository->findOneBy(['uuid' => $options['uuid']]);

        if (! $entity instanceof Invoice && ! $entity instanceof Quote) {
            throw new NotFoundHttpException(sprintf('"%s" with id %s does not exist', ucfirst((string) $options['entity']), $options['uuid']));
        }

        if ($this->emailVerificationGate->isCompanyGated($entity->getCompany())) {
            throw new NotFoundHttpException(sprintf('"%s" with id %s does not exist', ucfirst((string) $options['entity']), $options['uuid']));
        }

        try {
            if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
                return new RedirectResponse($this->router->generate($options['route'], ['id' => $entity->getId()]));
            }
        } catch (AuthenticationCredentialsNotFoundException) {
        }

        $entityId = $entity instanceof Invoice ? $entity->getInvoiceId() : $entity->getQuoteId();

        $this->companySelector->switchCompany($entity->getCompany()->getId());

        $asPdf = 'pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf();

        // Whoever got this far holds the client's link and is not one of the
        // company's own users, who were sent to the page inside the app above.
        $this->activity->record(
            $entity,
            $entity->getCompany(),
            $asPdf ? DocumentActivityType::Downloaded : DocumentActivityType::Viewed,
            userAgent: $request->headers->get('User-Agent'),
        );

        // Handle PDF format
        if ($asPdf) {
            $html = $this->twig->render($this->templateResolver->resolve($entity, BillingTemplateChannel::Pdf), [$options['entity'] => $entity]);
            $filename = sprintf('%s_%s.pdf', $options['entity'], $entityId);

            return new PdfResponse($this->pdfGenerator->generate($html), $filename);
        }

        return [
            $options['entity'] => $entity,
            'title' => $options['entity'] . ' #' . $entityId,
            'template' => $options['template'],
            'documentTemplate' => $this->templateResolver->customTemplate($entity, BillingTemplateChannel::View),
        ];
    }
}
