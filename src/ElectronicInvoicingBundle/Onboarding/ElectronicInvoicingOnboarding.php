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

namespace Augias\ElectronicInvoicingBundle\Onboarding;

use const JSON_THROW_ON_ERROR;
use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Registry\RegistryCompany;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdpProvider;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\Uid\Ulid;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtoupper;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * What a company still lacks to invoice electronically, and the three steps
 * of the setup that walks it there: who it is (SIREN, SIRET, VAT number,
 * address, VAT regime), the platform it goes through, and that platform's
 * verification of its identity.
 *
 * The values are the same ones the settings pages write: the assistant is a
 * shorter way to them, not a second copy.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Onboarding\ElectronicInvoicingOnboardingTest
 */
final readonly class ElectronicInvoicingOnboarding implements OnboardingProgressProviderInterface
{
    public const string ADDRESS_CONFIG_PATH = 'system/company/contact_details/address';

    public const string COMPANY_NAME_CONFIG_PATH = 'system/company/company_name';

    public function __construct(
        private CompanySelector $companySelector,
        private TaxIdentifierRepository $taxIdentifiers,
        private SystemConfig $systemConfig,
        private CompanyRegistry $registry,
        private ElectronicInvoiceProviderSettingRepository $settings,
        private SuperPdpConnector $superPdp,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The company as the settings hold it now: the form's starting values.
     */
    public function identity(): CompanyIdentity
    {
        $identifiers = $this->identifiers();
        $address = $this->address();

        return new CompanyIdentity(
            siren: $identifiers[TaxIdentifierType::SIREN] ?? null,
            siret: $identifiers[TaxIdentifierType::SIRET] ?? null,
            vatNumber: $identifiers[TaxIdentifierType::VAT_NUMBER] ?? null,
            street1: $address['street1'] ?? null,
            street2: $address['street2'] ?? null,
            zip: $address['zip'] ?? null,
            city: $address['city'] ?? null,
            vatExempt: $this->systemConfig->isVatExempt(),
        );
    }

    /**
     * What the State's register says of $siren, merged over what the company
     * typed: the register fills, it does not erase.
     */
    public function lookUp(CompanyIdentity $typed): CompanyIdentity
    {
        $siren = self::digits($typed->siren);
        $siret = self::digits($typed->siret);

        // The SIRET typed, when it is one of the SIREN's establishments: that
        // one, the one that invoices, not the head office.
        $found = $siren !== null && $siret !== null && strlen($siret) === 14 && str_starts_with($siret, $siren)
            ? $this->registry->findEstablishment($siret)
            : null;
        $found ??= $siren === null ? null : $this->registry->find($siren);

        if (! $found instanceof RegistryCompany) {
            return $typed;
        }

        return new CompanyIdentity(
            siren: $found->siren,
            siret: $found->siret ?? $typed->siret,
            vatNumber: $typed->vatExempt ? $typed->vatNumber : ($typed->vatNumber ?? $found->vatNumber()),
            street1: $found->street1 ?? $typed->street1,
            street2: $found->street2 ?? $typed->street2,
            zip: $found->zip ?? $typed->zip,
            city: $found->city ?? $typed->city,
            vatExempt: $typed->vatExempt,
            registryName: $found->name,
        );
    }

    public function registryHas(string $siren): bool
    {
        $siren = self::digits($siren);

        return $siren !== null && $this->registry->find($siren) instanceof RegistryCompany;
    }

    /**
     * Writes the identity where the settings keep it, and switches electronic
     * invoicing on: the company has just said it wants it.
     */
    public function saveIdentity(CompanyIdentity $identity): void
    {
        $existing = $this->taxIdentifierEntities();

        foreach ([
            TaxIdentifierType::SIREN => self::digits($identity->siren),
            TaxIdentifierType::SIRET => self::digits($identity->siret),
            TaxIdentifierType::VAT_NUMBER => self::compact($identity->vatNumber),
        ] as $label => $value) {
            if ($value === null) {
                continue;
            }

            $identifier = $existing[$label] ?? new TaxIdentifier()->setLabel($label);
            $identifier->setClient(null);
            $identifier->setValue($value);
            $this->entityManager->persist($identifier);
        }

        $this->entityManager->flush();

        $address = [
            'street1' => self::text($identity->street1),
            'street2' => self::text($identity->street2),
            'city' => self::text($identity->city),
            'state' => $this->address()['state'] ?? null,
            'zip' => self::text($identity->zip),
            'country' => $this->address()['country'] ?? 'FR',
        ];

        $this->systemConfig->set(self::ADDRESS_CONFIG_PATH, json_encode($address, JSON_THROW_ON_ERROR));
        $this->systemConfig->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, $identity->vatExempt ? '1' : '0');
        $this->systemConfig->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');

        if ($identity->registryName !== null && trim((string) $this->systemConfig->get(self::COMPANY_NAME_CONFIG_PATH)) === '') {
            $this->systemConfig->set(self::COMPANY_NAME_CONFIG_PATH, $identity->registryName);
        }
    }

    /**
     * Where the company stands, step by step.
     */
    public function progress(): OnboardingProgress
    {
        $identifiers = $this->identifiers();
        $vatExempt = $this->systemConfig->isVatExempt();
        $address = $this->address();

        $identityDone = isset($identifiers[TaxIdentifierType::SIREN], $identifiers[TaxIdentifierType::SIRET])
            && ($vatExempt || isset($identifiers[TaxIdentifierType::VAT_NUMBER]))
            && ($address['zip'] ?? null) !== null
            && ($address['city'] ?? null) !== null;

        $setting = $this->platform();

        return new OnboardingProgress(
            identityDone: $identityDone,
            hasSiren: isset($identifiers[TaxIdentifierType::SIREN]) || isset($identifiers[TaxIdentifierType::SIRET]),
            platform: $setting,
            connected: $setting instanceof ElectronicInvoiceProviderSetting && $this->isConnected($setting),
            verification: $setting?->getAccountVerification(),
            canConnectHere: $this->superPdp->isAvailable(),
        );
    }

    /**
     * The SUPER PDP setting the assistant connects, created when the company
     * has none yet. Switched on when no other platform is: the first one a
     * company sets up is the one its invoices go to. The caller flushes.
     */
    public function superPdpSetting(): ElectronicInvoiceProviderSetting
    {
        foreach ($this->settings->findAll() as $setting) {
            if ($setting->getProvider() === SuperPdpProvider::getName()) {
                return $setting;
            }
        }

        $setting = new ElectronicInvoiceProviderSetting()
            ->setName('SUPER PDP')
            ->setProvider(SuperPdpProvider::getName())
            ->setSettings([]);

        if ($this->settings->findSwitchedOn() === []) {
            $setting->setActive(true);
        }

        $this->entityManager->persist($setting);

        return $setting;
    }

    /**
     * The platform invoices go to, verified or not.
     */
    public function platform(): ?ElectronicInvoiceProviderSetting
    {
        return $this->settings->findSwitchedOn()[0] ?? null;
    }

    private function isConnected(ElectronicInvoiceProviderSetting $setting): bool
    {
        $settings = $setting->getSettings();

        return isset($settings['authorization']) || isset($settings['client_id']) || $setting->getProvider() !== SuperPdpProvider::getName();
    }

    /**
     * @return array<string, string> the company's own identifiers, by label
     */
    private function identifiers(): array
    {
        $values = [];
        foreach ($this->taxIdentifierEntities() as $label => $identifier) {
            $value = trim((string) $identifier->getValue());
            if ($value !== '') {
                $values[$label] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, TaxIdentifier> the first identifier of each label
     */
    private function taxIdentifierEntities(): array
    {
        $companyId = $this->companySelector->getCompany();
        if (! $companyId instanceof Ulid) {
            return [];
        }

        $identifiers = [];
        foreach ($this->taxIdentifiers->findCompanyIdentifiers($companyId) as $identifier) {
            $identifiers[(string) $identifier->getLabel()] ??= $identifier;
        }

        return $identifiers;
    }

    /**
     * @return array<string, string|null>
     */
    private function address(): array
    {
        $raw = $this->systemConfig->get(self::ADDRESS_CONFIG_PATH);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $address = [];
        foreach ($decoded as $key => $value) {
            $address[(string) $key] = is_string($value) ? self::text($value) : null;
        }

        return $address;
    }

    private static function digits(?string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    private static function compact(?string $value): ?string
    {
        $compact = (string) preg_replace('/\s+/', '', (string) $value);

        return $compact === '' ? null : mb_strtoupper($compact);
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
