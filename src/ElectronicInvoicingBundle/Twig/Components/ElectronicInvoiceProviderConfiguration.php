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

namespace Augias\ElectronicInvoicingBundle\Twig\Components;

use Augias\CoreBundle\Response\FlashResponse;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Form\Type\ElectronicInvoiceProviderSettingType;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceAccountCheckerInterface;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Uid\Ulid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use function assert;

/**
 * @see \Augias\ElectronicInvoicingBundle\Tests\Twig\Components\ElectronicInvoiceProviderConfigurationTest
 */
#[AsLiveComponent]
final class ElectronicInvoiceProviderConfiguration extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    /**
     * @var ElectronicInvoiceProviderSetting|string|null
     */
    #[LiveProp(writable: true, fieldName: 'formData', updateFromParent: true)]
    public $setting = null;

    #[LiveProp(writable: true, updateFromParent: true, url: true)]
    public ?string $provider = null;

    #[LiveProp(writable: true)]
    public ?bool $showDeleteConfirmation = false;

    /**
     * What the platform said when asked — null until the button is pressed.
     * Asked on demand, never on page load: it is a round trip to a third
     * party, and the page should not wait on it.
     *
     * @var array{verification: string, companyName: ?string, companyNumber: ?string, environment: ?string, error: ?string, warnings: list<string>}|null
     */
    #[LiveProp]
    public ?array $accountStatus = null;

    public function __construct(
        private readonly ElectronicInvoiceProviderSettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ElectronicInvoiceProviderRegistry $providers,
        private readonly ElectronicInvoiceAccountMonitor $accountMonitor,
    ) {
    }

    /**
     * Whether this provider can say who its account belongs to and whether
     * that identity is verified — the button is only offered then.
     */
    #[ExposeInTemplate]
    public function canCheckAccount(): bool
    {
        return ! $this->isNewSetting()
            && $this->providers->get($this->providerSetting()->getProvider()) instanceof ElectronicInvoiceAccountCheckerInterface;
    }

    /**
     * Asks the platform, with the saved credentials, whose account this is and
     * whether it may be used — a platform refuses everything until it has
     * verified the company's identity, and says nothing more useful than 403.
     */
    #[LiveAction]
    public function checkAccount(): void
    {
        $status = $this->accountMonitor->check($this->providerSetting());

        if (null === $status) {
            return;
        }

        // Recorded as well as shown: the answer is what turns electronic
        // invoicing on or off.
        $this->entityManager->flush();
        $this->accountStatus = $status->toArray();
    }

    #[ExposeInTemplate]
    public function providerSetting(): ElectronicInvoiceProviderSetting
    {
        if (is_string($this->setting) && $this->setting !== '') {
            $loadedSetting = $this->repository->find(Ulid::fromString($this->setting));

            if (! $loadedSetting instanceof ElectronicInvoiceProviderSetting) {
                throw $this->createNotFoundException(sprintf('Provider setting with ID "%s" not found', $this->setting));
            }

            $this->setting = $loadedSetting;
        }

        if ($this->setting instanceof ElectronicInvoiceProviderSetting) {
            return $this->setting;
        }

        $this->setting = new ElectronicInvoiceProviderSetting();

        if ($this->provider !== null && $this->provider !== '') {
            $this->setting->setProvider($this->provider);
        }

        return $this->setting;
    }

    #[ExposeInTemplate]
    public function isNewSetting(): bool
    {
        return ! $this->providerSetting()->getId() instanceof Ulid;
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(ElectronicInvoiceProviderSettingType::class, $this->providerSetting());
    }

    #[LiveAction]
    public function save(): Response
    {
        $this->submitForm();
        $form = $this->getForm();

        if (! $form->isValid()) {
            $this->flash(FlashResponse::FLASH_ERROR, 'einvoicing.provider.flash.validation_errors');

            return $this->redirectToRoute('_einvoicing_providers');
        }

        /** @var ElectronicInvoiceProviderSetting $setting */
        $setting = $form->getData();
        $isNew = $this->isNewSetting();

        // Only one provider is ever "active" (the one invoice sending
        // dispatches to) — the very first one configured for a company
        // becomes active automatically so there is always something to send
        // to as soon as one provider is set up.
        // Switched on, verified or not: one held by its platform is still the
        // one the company chose, and a second must not be switched on beside it.
        if ($isNew && $this->repository->findSwitchedOn() === []) {
            $setting->setActive(true);
        }

        // Asked as soon as there are credentials to ask with: a platform that
        // has not verified the company refuses everything, and the user should
        // learn that now rather than on the first invoice.
        $status = $this->accountMonitor->check($setting);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        $this->flash(FlashResponse::FLASH_SUCCESS, $isNew ? 'einvoicing.provider.flash.added' : 'einvoicing.provider.flash.updated');

        if (null !== $status && ! $status->verification->isUsable()) {
            $this->flash(FlashResponse::FLASH_WARNING, $status->answered ? 'einvoicing.account.held' : 'einvoicing.account.unchecked');
        }

        return $this->redirectToRoute('_einvoicing_providers');
    }

    #[LiveAction]
    public function showDeleteConfirmation(): void
    {
        $this->showDeleteConfirmation = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->showDeleteConfirmation = false;
    }

    #[LiveAction]
    public function confirmDelete(): Response
    {
        $setting = $this->providerSetting();

        if ($this->isNewSetting()) {
            $this->flash(FlashResponse::FLASH_ERROR, 'einvoicing.provider.flash.not_exist');

            return $this->redirectToRoute('_einvoicing_providers');
        }

        $wasActive = $setting->isActive();
        $this->entityManager->remove($setting);
        $this->entityManager->flush();

        if ($wasActive) {
            $next = $this->repository->findOneBy([]);

            if ($next instanceof ElectronicInvoiceProviderSetting) {
                $next->setActive(true);
                $this->entityManager->flush();
            }
        }

        $this->flash(FlashResponse::FLASH_INFO, 'einvoicing.provider.flash.deleted');

        return $this->redirectToRoute('_einvoicing_providers');
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        assert($session instanceof Session);
        $session->getFlashBag()->add($type, $message);
    }
}
