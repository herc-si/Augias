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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceDisputedNotification;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoiceRejectedNotification;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpAccessTokens;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdpProvider;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\NotificationBundle\Notification\NotificationManager;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_diff;
use function array_values;
use function date_default_timezone_get;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Asks SUPER PDP where each sent invoice stands, keeps every step it dated,
 * and tells the company what needs it — run hourly by
 * {@see \Augias\ElectronicInvoicingBundle\Command\PollSuperPdpInvoiceStatusCommand}
 * for every company, and on demand for one ("Synchroniser maintenant").
 *
 * SUPER PDP exposes no webhooks (see https://api.superpdp.tech/openapi/superpdp.json),
 * only `GET /v1.beta/invoices/{id}`.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Command\PollSuperPdpInvoiceStatusCommandTest
 */
final readonly class SuperPdpStatusRefresher
{
    /**
     * A submission stops being polled once it reaches one of these: rejected,
     * or paid — nothing comes after either. {@see SuperPdpProvider} is the
     * single source of truth for what each status code means.
     */
    private const array FINAL_STATUS_CODES = [
        ...SuperPdpProvider::REJECTED_STATUS_CODES,
        SuperPdpProvider::PAID_STATUS_CODE,
    ];

    /**
     * Accepted, with only the payment left to come: followed for so long
     * after it was sent, so the history shows when it was paid, then left —
     * not every invoice is paid through the platform, and those would be
     * asked about forever.
     */
    private const string FOLLOWED_FOR = '-90 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ElectronicInvoiceSubmissionRepository $submissionRepository,
        private ElectronicInvoiceProviderSettingRepository $settingRepository,
        private SuperPdpClient $client,
        private SuperPdpAccessTokens $tokens,
        private NotificationManager $notificationManager,
        private LoggerInterface $logger,
        private ElectronicInvoicingAlerts $alerts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The submissions still worth asking about — of $company alone when
     * given, of whichever companies the company filter lets through when not.
     *
     * @return array{updated: int, errors: list<string>}
     */
    public function refreshPending(?Company $company = null): array
    {
        $updated = 0;
        $errors = [];

        $submissions = $this->submissionRepository->findPendingByProvider(
            SuperPdpProvider::getName(),
            self::FINAL_STATUS_CODES,
            array_values(array_diff(SuperPdpProvider::ACCEPTED_STATUS_CODES, [SuperPdpProvider::PAID_STATUS_CODE])),
            $this->clock->now()->modify(self::FOLLOWED_FOR),
        );

        foreach ($submissions as $submission) {
            if ($company instanceof Company && ! $submission->getCompany()->getId()->equals($company->getId())) {
                continue;
            }

            try {
                if ($this->refreshStatus($submission)) {
                    ++$updated;
                }
            } catch (SuperPdpApiException $e) {
                $errors[] = sprintf('Could not refresh status for submission %s: %s', (string) $submission->getId(), $e->getMessage());
            }
        }

        $this->entityManager->flush();

        return ['updated' => $updated, 'errors' => $errors];
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

        if (! $this->tokens->hasCredentials($setting->getSettings())) {
            return false;
        }

        $accessToken = $this->tokens->accessToken($setting->getSettings());
        $invoice = $this->client->getInvoice($accessToken, $externalReference);

        $recorded = $this->recordEvents($submission, $invoice['events'] ?? null);

        $event = SuperPdpProvider::latestEvent($invoice['events'] ?? null);
        $statusCode = SuperPdpProvider::latestStatusCode($invoice['events'] ?? null);

        if ($statusCode === null || $statusCode === $submission->getStatusCode()) {
            return $recorded;
        }

        $submission->setStatusCode($statusCode);

        if (in_array($statusCode, SuperPdpProvider::REJECTED_STATUS_CODES, true)) {
            $this->notifyRejection($submission, $statusCode);
        }

        if (SuperPdpProvider::DISPUTED_STATUS_CODE === $statusCode) {
            $this->notifyDispute($submission, $event ?? []);
        }

        if (SuperPdpProvider::SUSPENDED_STATUS_CODE === $statusCode) {
            [$reason, $note] = self::detailOf($event ?? []);
            $this->alerts->raise($submission->getCompany(), ElectronicInvoicingProblem::Suspended, $submission->getInvoice(), $note ?? $reason);
        }

        return true;
    }

    /**
     * Every step the platform has dated, not just the latest: what the
     * invoice page shows as its history. True when one was new.
     */
    private function recordEvents(ElectronicInvoiceSubmission $submission, mixed $events): bool
    {
        if (! is_array($events)) {
            return false;
        }

        $recorded = false;
        $zone = new DateTimeZone(date_default_timezone_get());

        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['id']) || ! is_string($event['status_code'] ?? null)) {
                continue;
            }

            try {
                // The column keeps no zone and is read back in the application's.
                $occurredAt = new DateTimeImmutable(is_string($event['created_at'] ?? null) ? $event['created_at'] : 'now')->setTimezone($zone);
            } catch (Exception) {
                continue;
            }

            [$reason, $note] = self::detailOf($event);

            $recorded = $submission->recordEvent((string) $event['id'], $event['status_code'], $occurredAt, $reason, $note) || $recorded;
        }

        return $recorded;
    }

    /**
     * The reason code (MDT-113) and the first note an event came with.
     *
     * @param array<mixed> $event
     *
     * @return array{?string, ?string}
     */
    private static function detailOf(array $event): array
    {
        $detail = is_array($event['details'][0] ?? null) ? $event['details'][0] : [];
        $reason = is_string($detail['reason'] ?? null) && '' !== $detail['reason'] ? $detail['reason'] : null;
        $note = $detail['notes'][0]['contents'][0]['content'] ?? null;

        return [$reason, is_string($note) && '' !== $note ? $note : null];
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
        [$code, $note] = self::detailOf($event);
        $reason = ResponseReason::tryFrom($code ?? '');

        try {
            $this->notificationManager->sendNotification(
                new ElectronicInvoiceDisputedNotification([
                    ...$this->documentContext($submission),
                    'reason' => $reason?->translationKey() ?? $code,
                    'note' => $note,
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
                    ...$this->documentContext($submission),
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

    /**
     * What the notification e-mails name: the document sent, invoice or
     * credit note, its number, its client, and which page to open.
     *
     * @return array{invoice: Invoice|CreditNote, client: ?Client, number: string, creditNote: ?CreditNote}
     */
    private function documentContext(ElectronicInvoiceSubmission $submission): array
    {
        $document = $submission->getDocument();

        return [
            'invoice' => $document,
            'client' => $document->getClient(),
            'number' => $submission->getDocumentNumber(),
            'creditNote' => $document instanceof CreditNote ? $document : null,
        ];
    }
}
