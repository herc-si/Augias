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

namespace Augias\ElectronicInvoicingBundle\Provider;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by each electronic-invoicing platform (Chorus Pro, a PDP, ...).
 *
 * A provider owns both generating its own document format (Factur-X, UBL,
 * CII, ...) and transmitting it — there is deliberately no shared central
 * generator, so adding a new platform is just a new class implementing this
 * interface, tagged for auto-discovery like Augias\NotificationBundle's
 * ConfiguratorInterface.
 */
#[AutoconfigureTag(self::DI_TAG)]
interface ElectronicInvoiceProviderInterface
{
    public const string DI_TAG = 'einvoicing.provider';

    /**
     * Unique machine key for this provider, e.g. "chorus_pro". Stored on
     * ElectronicInvoiceProviderSetting::$provider to resolve back to the
     * tagged service.
     */
    public static function getName(): string;

    /**
     * FQCN of the Symfony Form Type used to configure this provider's
     * settings (API credentials, endpoint, ...).
     */
    public function getForm(): string;

    /**
     * Generate and transmit the electronic invoice — or credit note — for $invoice using this
     * provider's own $config (ElectronicInvoiceProviderSetting::$settings).
     *
     * @param array<string, mixed> $config
     */
    public function send(Invoice | CreditNote $invoice, array $config): ElectronicInvoiceSubmissionResult;

    /**
     * Classify $submission's provider-reported outcome (its `success` flag and,
     * once polled, its raw `statusCode`) into Augias's own normalized
     * outcome — used to display a consistent status alongside the invoice and
     * to decide when to alert users, regardless of which provider sent it.
     */
    public function resolveProcessingStatus(ElectronicInvoiceSubmission $submission): ElectronicInvoiceProcessingStatus;
}
