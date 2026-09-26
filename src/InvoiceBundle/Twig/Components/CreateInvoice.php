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

namespace Augias\InvoiceBundle\Twig\Components;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Repository\ProductRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Enum\CustomFieldTarget;
use Augias\CoreBundle\Service\CustomField\CustomFieldFormWriter;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\MoneyBundle\Calculator;
use Augias\MoneyBundle\Currency\CurrencyScale;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Service\TaxAvailability;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Money\Currency;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @see \Augias\InvoiceBundle\Tests\Twig\Components\CreateInvoiceTest
 */
#[AsLiveComponent]
final class CreateInvoice extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    public InvoiceFormDTO $dto;

    #[LiveProp(writable: false)]
    public bool $isEdit = false;

    #[LiveProp(writable: false, fieldName: 'invoiceEntity')]
    public ?Invoice $invoice = null;

    #[LiveProp(writable: true)]
    public ?string $previousClientId = null;

    /**
     * Bound to the catalogue picker. Reset after each insert so picking the
     * same entry twice in a row still fires a change.
     */
    #[LiveProp(writable: true)]
    public ?string $catalogProductId = null;

    public function __construct(
        private readonly TaxAvailability $taxAvailability,
        private readonly ClientRepository $clientRepository,
        private readonly TotalCalculator $totalCalculator,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowInterface $invoiceStateMachine,
        private readonly MailerInterface $mailer,
        private readonly RouterInterface $router,
        private readonly InvoiceFormManager $formManager,
        private readonly Calculator $calculator,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly CustomFieldFormWriter $customFieldFormWriter,
        private readonly FeatureGate $featureGate,
        private readonly ProductRepository $productRepository,
        private readonly CurrencyScale $currencyScale,
        private readonly SystemConfig $systemConfig
    ) {
        $this->dto = new InvoiceFormDTO();
    }

    /**
     * Auto-select contacts on initial mount when a client is pre-selected.
     * Priority 10 ensures this runs BEFORE initializeForm() (priority 0) so
     * the DTO has contacts set before the form is created.
     */
    #[PostMount(priority: 10)]
    public function initializeContacts(): void
    {
        $client = $this->dto->client;

        // Auto-select all contacts if client is set but no users are selected
        if ($client instanceof Client && $this->dto->users->isEmpty()) {
            foreach ($client->getContacts() as $contact) {
                $this->dto->users->add($contact);
            }

            // Track the client so we don't re-select on subsequent renders
            $this->previousClientId = (string) $client->getId();
        }
    }

    /**
     * Auto-select contacts when client changes during re-render.
     * Priority 10 ensures this runs BEFORE submitFormOnRender() (priority 0)
     * so the contacts are included in the form submission.
     */
    #[PreReRender(priority: 10)]
    public function autoSelectContactsOnClientChange(): void
    {
        $this->maybeAutoSelectContacts();
    }

    /**
     * Calculate totals after form submission.
     * Priority -10 ensures this runs AFTER submitFormOnRender() (priority 0)
     * so the DTO has been updated with the new form values.
     *
     * @throws MathException
     */
    #[PreReRender(priority: -10)]
    public function calculateTotals(): void
    {
        // Skip calculation if data is incomplete
        if (! $this->canCalculateTotals()) {
            $this->dto->total = '0';
            $this->dto->baseTotal = '0';
            $this->dto->tax = '0';
            $this->dto->disbursementTotal = '0';
            return;
        }

        // Create temporary invoice from DTO to calculate totals
        $tempInvoice = $this->formManager->createInvoiceFromDTO($this->dto);
        $this->totalCalculator->calculateTotals($tempInvoice);

        // Copy calculated totals back to DTO (convert BigNumber to string)
        $this->dto->total = (string) $tempInvoice->getTotal();
        $this->dto->baseTotal = (string) $tempInvoice->getBaseTotal();
        $this->dto->tax = (string) $tempInvoice->getTax();
        $this->dto->disbursementTotal = (string) $tempInvoice->getDisbursementTotal();
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $options = [];

        // Set currency based on client (existing or from DTO) or use system default
        if ($this->dto->client instanceof Client) {
            $options['currency'] = $this->dto->client->getCurrency();
        } elseif (($this->formValues['client'] ?? '') !== '') {
            $client = $this->clientRepository->find($this->formValues['client']);
            $options['currency'] = $client?->getCurrency();
        }

        if ($this->isEdit && $this->invoice instanceof Invoice && $this->invoice->getId() instanceof Ulid) {
            $options['existing_target_id'] = $this->invoice->getId();
        }

        return $this->createForm(InvoiceType::class, $this->dto, $options);
    }

    /**
     * @return list<Product>
     */
    public function getCatalogProducts(): array
    {
        return $this->productRepository->findActiveForPicker();
    }

    /**
     * Appends a line pre-filled from a catalogue entry.
     *
     * Writes into $formValues rather than the DTO: the DTO is not a LiveProp,
     * so it is rebuilt from the submitted form on every render and any object
     * mutated here would be discarded. This mirrors what LiveCollectionTrait
     * does for an empty row, only with the catalogue's values already in place.
     *
     * The values are copied, not referenced: editing the line afterwards, or
     * the catalogue later, must never rewrite the other.
     */
    #[LiveAction]
    public function addFromCatalog(PropertyAccessorInterface $propertyAccessor): void
    {
        $productId = $this->catalogProductId;
        $this->catalogProductId = null;

        if ($productId === null || $productId === '') {
            return;
        }

        $product = $this->productRepository->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $lines = $propertyAccessor->getValue($this->formValues, '[lines]');

        if (! is_array($lines)) {
            $lines = [];
            $propertyAccessor->setValue($this->formValues, '[lines]', $lines);
        }

        $index = $lines === [] ? 0 : max(array_keys($lines)) + 1;

        $line = [
            'description' => $this->catalogLineDescription($product),
            'price' => $this->catalogLinePrice($product),
            'qty' => '1',
        ];

        // The form only has the field where tax is offered; sending it anyway
        // would fail as an extra field.
        if ($this->hasTax()) {
            $line['supplyType'] = $product->getType()->supplyType()->value;
        }

        $tax = $product->getTax();

        if ($tax instanceof Tax) {
            $line['taxes'] = [['tax' => (string) $tax->getId()]];
        }

        $propertyAccessor->setValue($this->formValues, sprintf('[lines][%d]', $index), $line);
    }

    /**
     * The catalogue stores prices in minor units; this feeds a form value, and
     * the money field's view data is in major units. Handing over the stored
     * integer put every catalogue line out by a factor of a hundred.
     *
     * The currency is resolved the same way instantiateForm() does, so the
     * conversion uses the same scale the field will apply in reverse - and the
     * factor follows the currency's own decimal count rather than a flat 100,
     * which is what keeps JPY and BHD right.
     */
    private function catalogLinePrice(Product $product): string
    {
        $price = $product->getSalePrice() ?? BigInteger::zero();

        return (string) $this->currencyScale->toMajorUnit($price, $this->currentCurrency());
    }

    private function currentCurrency(): Currency
    {
        if ($this->dto->client instanceof Client) {
            return $this->dto->client->getCurrency();
        }

        if (($this->formValues['client'] ?? '') !== '') {
            $currency = $this->clientRepository->find($this->formValues['client'])?->getCurrency();

            if ($currency instanceof Currency) {
                return $currency;
            }
        }

        return $this->systemConfig->getCurrency();
    }

    /**
     * The catalogue keeps name and description apart so the list stays readable;
     * a document line has one text field, so they are joined here.
     */
    private function catalogLineDescription(Product $product): string
    {
        $description = trim((string) $product->getDescription());

        if ($description === '') {
            return (string) $product->getName();
        }

        return $product->getName() . "\n" . $description;
    }

    #[LiveAction]
    public function clearClient(): void
    {
        $this->formValues['client'] = null;
        $this->formValues['users'] = [];
        $this->previousClientId = null;
    }

    #[LiveAction]
    public function saveDraft(): ?Response
    {
        return $this->saveInvoice('draft');
    }

    #[LiveAction]
    public function saveUpdate(): ?Response
    {
        return $this->saveInvoice('save');
    }

    #[LiveAction]
    public function savePublish(): ?Response
    {
        return $this->saveInvoice('publish');
    }

    #[LiveAction]
    public function saveSend(): ?Response
    {
        if ($this->emailVerificationGate->isGated()) {
            $this->addFlash('error', 'email_verification.flash.send_invoice');
            return null;
        }

        return $this->saveInvoice('send');
    }

    private function saveInvoice(string $action): ?Response
    {
        $this->submitForm();

        $form = $this->getForm();

        if (! $form->isValid()) {
            // Totals already recalculated by PreReRender hook
            return null;
        }

        /** @var InvoiceFormDTO $dto */
        $dto = $form->getData();

        // Convert DTO to Invoice entity
        // If clientMode=New, manager creates unpersisted client
        // Client will be persisted via cascade when invoice is saved
        if ($this->isEdit) {
            assert($this->invoice instanceof Invoice);

            $this->formManager->updateInvoiceFromDTO($this->invoice, $dto);

            if (('send' === $action || 'publish' === $action) && $this->invoiceStateMachine->can($this->invoice, Graph::TRANSITION_ACCEPT)) {
                $this->invoiceStateMachine->apply($this->invoice, Graph::TRANSITION_ACCEPT);
            }

            $invoiceId = $this->invoice->getId();
            if ($invoiceId instanceof Ulid && $this->featureGate->isEnabled(Feature::CustomFields->value) && $form->has('customFields')) {
                $this->customFieldFormWriter->write(
                    $form->get('customFields'),
                    CustomFieldTarget::INVOICE,
                    $invoiceId,
                    $this->invoice,
                );
            }

            $this->entityManager->flush();

            if ('send' === $action) {
                if ($this->invoice->getUsers()->isEmpty()) {
                    $this->addFlash('error', 'invoice.send.no_recipients');
                } else {
                    $this->mailer->send(new InvoiceEmail($this->invoice));
                }
            }

            $this->addFlash('success', 'invoice.edit.success');

            $url = $this->router->generate('_invoices_view', ['id' => $this->invoice->getId()]);
            return $this->redirect($url);
        }

        $invoice = $this->formManager->createInvoiceFromDTO($dto);

        // Apply state transitions
        if (! $invoice->getId() instanceof Ulid) {
            $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
        }

        // Publish the invoice if the action is 'send' or 'publish'
        if ('send' === $action || 'publish' === $action) {
            $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
        }

        // Persist invoice (client cascades if new)
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $newInvoiceId = $invoice->getId();
        if ($newInvoiceId instanceof Ulid && $this->featureGate->isEnabled(Feature::CustomFields->value) && $form->has('customFields')) {
            $this->customFieldFormWriter->write(
                $form->get('customFields'),
                CustomFieldTarget::INVOICE,
                $newInvoiceId,
                $invoice,
            );
            $this->entityManager->flush();
        }

        // Send the invoice only if the action is 'send'
        if ('send' === $action) {
            if ($invoice->getUsers()->isEmpty()) {
                $this->addFlash('error', 'invoice.send.no_recipients');
            } else {
                $this->mailer->send(new InvoiceEmail($invoice));
            }
        }

        // Add flash message and redirect
        $this->addFlash('success', 'invoice.create.success');

        $url = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);
        return $this->redirect($url);
    }

    #[ExposeInTemplate]
    public function hasTax(): bool
    {
        return $this->taxAvailability->isOffered();
    }

    #[ExposeInTemplate]
    public function hasTermsOrNotes(): bool
    {
        return ($this->dto->terms !== null && $this->dto->terms !== '')
            || ($this->dto->notes !== null && $this->dto->notes !== '');
    }

    #[ExposeInTemplate]
    public function hasClients(): bool
    {
        return $this->clientRepository->getTotalClients() > 0;
    }

    /**
     * Calculate discount amount from DTO for template display
     */
    #[ExposeInTemplate]
    public function getDiscountAmount(): string
    {
        if (! $this->dto->discount instanceof Discount) {
            return '0';
        }

        // Skip calculation if we can't create an invoice (incomplete data)
        if (! $this->canCalculateTotals()) {
            return '0';
        }

        // Create temporary invoice to leverage Calculator
        try {
            $tempInvoice = $this->formManager->createInvoiceFromDTO($this->dto);

            // Recalculated from the lines rather than trusted from the DTO: a
            // percentage discount is worked out against baseTotal, and that
            // arrives here as a hidden form field maintained by the
            // browser. While a line is being edited it lags, and a discount
            // read against a stale zero shows as no discount at all.
            $this->totalCalculator->calculateTotals($tempInvoice);

            return (string) $this->calculator->calculateDiscount($tempInvoice);
        } catch (InvalidArgumentException) {
            // Client data incomplete during mode switching
            return '0';
        }
    }

    /**
     * Check if we have enough data to calculate totals
     */
    private function canCalculateTotals(): bool
    {
        // Need at least one line item
        if ($this->dto->lines->isEmpty()) {
            return false;
        }

        // Check client data based on mode
        if ($this->dto->clientMode === InvoiceClientMode::Existing) {
            return $this->dto->client instanceof Client;
        }

        // NewClient mode - need inline data
        return $this->dto->hasInlineClientData();
    }

    /**
     * Auto-select all contacts when client changes during re-render.
     */
    private function maybeAutoSelectContacts(): void
    {
        $currentClientId = $this->formValues['client'] ?? null;

        // Skip if no client selected
        if ($currentClientId === null || $currentClientId === '') {
            return;
        }

        // Skip if client hasn't changed (already processed)
        if ($this->previousClientId === $currentClientId) {
            return;
        }

        // Update tracking and auto-select contacts
        $this->previousClientId = $currentClientId;
        $this->autoSelectContacts($currentClientId);
    }

    /**
     * Auto-select all contacts for the given client.
     */
    private function autoSelectContacts(string $clientId): void
    {
        $client = $this->clientRepository->find($clientId);
        if (! $client instanceof Client) {
            return;
        }

        // Auto-select all contacts
        $contactIds = [];
        foreach ($client->getContacts() as $contact) {
            $contactIds[] = (string) $contact->getId();
        }

        $this->formValues['users'] = $contactIds;
    }
}
