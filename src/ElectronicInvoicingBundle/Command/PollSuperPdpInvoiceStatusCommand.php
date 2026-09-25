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

namespace Augias\ElectronicInvoicingBundle\Command;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceDisputedNotification;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceRejectedNotification;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdpProvider;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\NotificationBundle\Notification\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Throwable;
use function assert;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * SUPER PDP exposes no webhooks (see
 * https://api.superpdp.tech/openapi/superpdp.json) — only polling via
 * `GET /v1.beta/invoices/{id}` — so this periodically refreshes the last
 * known `status_code` on successful {@see ElectronicInvoiceSubmission}
 * records until they reach a terminal status.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Command\PollSuperPdpInvoiceStatusCommandTest
 */
#[AsCommand(
    name: 'augias:einvoicing:poll-super-pdp-status',
    description: 'Refresh the processing status of invoices submitted to SUPER PDP',
)]
#[AsCronTask('#hourly', schedule: 'poll_super_pdp_invoice_status')]
final class PollSuperPdpInvoiceStatusCommand extends Command
{
    /**
     * A submission stops being polled once it reaches one of these — the union
     * of {@see SuperPdpProvider::ACCEPTED_STATUS_CODES} and
     * {@see SuperPdpProvider::REJECTED_STATUS_CODES}, SuperPdpProvider being the
     * single source of truth for what each status code means.
     */
    private const array TERMINAL_STATUS_CODES = [
        ...SuperPdpProvider::ACCEPTED_STATUS_CODES,
        ...SuperPdpProvider::REJECTED_STATUS_CODES,
    ];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ElectronicInvoiceSubmissionRepository $submissionRepository,
        private readonly ElectronicInvoiceProviderSettingRepository $settingRepository,
        private readonly SuperPdpClient $client,
        private readonly NotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $entityManager = $this->registry->getManagerForClass(ElectronicInvoiceSubmission::class);
        assert($entityManager instanceof EntityManagerInterface);

        $filters = $entityManager->getFilters();
        $companyFilterEnabled = $filters->isEnabled('company');

        if ($companyFilterEnabled) {
            $filters->disable('company');
        }

        $updated = 0;
        $errors = 0;

        try {
            $submissions = $this->submissionRepository->findPendingByProvider(SuperPdpProvider::getName(), self::TERMINAL_STATUS_CODES);

            foreach ($submissions as $submission) {
                try {
                    if ($this->refreshStatus($submission)) {
                        ++$updated;
                    }
                } catch (SuperPdpApiException $e) {
                    ++$errors;
                    $this->io->error(sprintf('Could not refresh status for submission %s: %s', (string) $submission->getId(), $e->getMessage()));
                }
            }

            $entityManager->flush();
        } finally {
            if ($companyFilterEnabled) {
                $filters->enable('company');
            }
        }

        $this->io->success(sprintf('Refreshed %d submission(s). Errors: %d', $updated, $errors));

        return self::SUCCESS;
    }

    /**
     * @throws SuperPdpApiException
     */
    private function refreshStatus(ElectronicInvoiceSubmission $submission): bool
    {
        $externalReference = $submission->getExternalReference();

        if ($externalReference === null || $externalReference === '') {
            return false;
        }

        $setting = $this->settingRepository->findActiveForCompany($submission->getCompany()->getId(), SuperPdpProvider::getName());

        if (! $setting instanceof ElectronicInvoiceProviderSetting) {
            return false;
        }

        $settings = $setting->getSettings();
        $clientId = $settings['client_id'] ?? null;
        $clientSecret = $settings['client_secret'] ?? null;

        if (! is_string($clientId) || ! is_string($clientSecret)) {
            return false;
        }

        $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
        $invoice = $this->client->getInvoice($accessToken, $externalReference);

        $event = SuperPdpProvider::latestEvent($invoice['events'] ?? null);
        $statusCode = SuperPdpProvider::latestStatusCode($invoice['events'] ?? null);

        if ($statusCode === null || $statusCode === $submission->getStatusCode()) {
            return false;
        }

        $submission->setStatusCode($statusCode);

        if (in_array($statusCode, SuperPdpProvider::REJECTED_STATUS_CODES, true)) {
            $this->notifyRejection($submission, $statusCode);
        }

        if (SuperPdpProvider::DISPUTED_STATUS_CODE === $statusCode) {
            $this->notifyDispute($submission, $event ?? []);
        }

        return true;
    }

    /**
     * A dispute needs someone too: the client says what is wrong — the
     * reason code and their own words — and the company settles it, usually
     * with a credit note or a corrected invoice.
     *
     * @param array<mixed> $event
     */
    private function notifyDispute(ElectronicInvoiceSubmission $submission, array $event): void
    {
        $detail = is_array($event['details'][0] ?? null) ? $event['details'][0] : [];
        $reason = ResponseReason::tryFrom(is_string($detail['reason'] ?? null) ? $detail['reason'] : '');
        $note = $detail['notes'][0]['contents'][0]['content'] ?? null;

        try {
            $this->notificationManager->sendNotification(
                new ElectronicInvoiceDisputedNotification([
                    'invoice' => $submission->getInvoice(),
                    'client' => $submission->getInvoice()->getClient(),
                    'reason' => $reason?->translationKey() ?? (is_string($detail['reason'] ?? null) ? $detail['reason'] : null),
                    'note' => is_string($note) && '' !== $note ? $note : null,
                ])
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to send electronic invoice dispute notification', [
                'submission_id' => (string) $submission->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Alerts internal users once a submission reaches a rejected terminal status
     * — this is the one outcome that needs a human to act on it (fix the
     * invoice/client data and resend); an accepted submission needs no action,
     * so it stays silent beyond the status shown on the invoice itself.
     */
    private function notifyRejection(ElectronicInvoiceSubmission $submission, string $statusCode): void
    {
        try {
            $this->notificationManager->sendNotification(
                new ElectronicInvoiceRejectedNotification([
                    'invoice' => $submission->getInvoice(),
                    'client' => $submission->getInvoice()->getClient(),
                    'submission' => $submission,
                    'statusCode' => $statusCode,
                ])
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to send electronic invoice rejection notification', [
                'submission_id' => (string) $submission->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
