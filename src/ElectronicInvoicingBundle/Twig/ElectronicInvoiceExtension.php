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

namespace Augias\ElectronicInvoicingBundle\Twig;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Override;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function is_callable;

final class ElectronicInvoiceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ElectronicInvoiceProviderRegistry $registry,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('einvoicing_processing_status', $this->resolveProcessingStatus(...)),
            new TwigFunction('einvoicing_latest_status_label', $this->renderLatestStatusLabel(...), ['is_safe' => ['html'], 'needs_environment' => true]),
        ];
    }

    /**
     * Where an invoice stands on the platform, at a glance — the status of
     * its latest submission, the one a resend replaced the others with — or
     * nothing for an invoice that never went out electronically.
     *
     * @param iterable<ElectronicInvoiceSubmission> $submissions
     */
    public function renderLatestStatusLabel(Environment $environment, iterable $submissions): string
    {
        $latest = null;

        foreach ($submissions as $submission) {
            if (! $latest instanceof ElectronicInvoiceSubmission || $submission->getCreated() >= $latest->getCreated()) {
                $latest = $submission;
            }
        }

        if (! $latest instanceof ElectronicInvoiceSubmission) {
            return '';
        }

        $label = $environment->getFunction('einvoicing_status_label')?->getCallable();

        return is_callable($label) ? (string) $label($environment, $this->resolveProcessingStatus($latest)) : '';
    }

    /**
     * Falls back to the submission's own success flag if its provider is no
     * longer configured (e.g. it was removed after the submission was sent) —
     * there is no provider left to classify a status code into Accepted, but
     * an outright failed submission is still known to be Rejected.
     */
    public function resolveProcessingStatus(ElectronicInvoiceSubmission $submission): ElectronicInvoiceProcessingStatus
    {
        $provider = $this->registry->get($submission->getProvider());

        if ($provider !== null) {
            return $provider->resolveProcessingStatus($submission);
        }

        return $submission->isSuccess() ? ElectronicInvoiceProcessingStatus::Pending : ElectronicInvoiceProcessingStatus::Rejected;
    }
}
