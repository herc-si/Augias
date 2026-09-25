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
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\ElectronicInvoicingBundle\Form\Type\Provider\SuperPdpConfigType;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\FacturXInvoiceBuilder;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Throwable;
use function array_key_last;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function str_contains;
use function usort;

/**
 * Sends the invoice as a Factur-X document to SUPER PDP
 * (https://www.superpdp.tech), a French Plateforme Agréée (PA/PDP) for the
 * electronic-invoicing reform, and, more generally, the Peppol network.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdpProviderTest
 */
#[AsTaggedItem('super_pdp')]
final readonly class SuperPdpProvider implements ElectronicInvoiceProviderInterface, ElectronicInvoiceReceiverInterface, ElectronicInvoiceAccountCheckerInterface
{
    /**
     * `fr:*` codes per https://api.superpdp.tech/openapi/superpdp.json that mean
     * the invoice reached its recipient without being refused (fr:205 Accepted,
     * fr:206 Partly accepted, fr:209 Completed) — the single source of truth for
     * this, also consumed by {@see \Augias\ElectronicInvoicingBundle\Command\PollSuperPdpInvoiceStatusCommand}.
     */
    public const array ACCEPTED_STATUS_CODES = ['fr:205', 'fr:206', 'fr:209'];

    /**
     * fr:210 Refused, fr:213 Rejected, fr:501 Inadmissible, plus the `api:*`
     * codes that mean SUPER PDP itself will not process the invoice any further.
     */
    public const array REJECTED_STATUS_CODES = ['fr:210', 'fr:213', 'fr:501', 'api:rejected', 'api:invalid'];

    public function __construct(
        private FacturXInvoiceBuilder $documentBuilder,
        private SuperPdpClient $client,
        private LoggerInterface $logger,
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * Whose account this is, whether SUPER PDP has verified that identity, and
     * whether the VAT settings it holds for the company agree with Augias's.
     *
     * Those settings are not decoration: SUPER PDP files the company's
     * e-reporting to the tax administration on the schedule its VAT regime
     * sets, and reports payments for services only when VAT is not on debits.
     *
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     */
    public function checkAccount(array $config): ElectronicInvoiceAccountStatus
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            return ElectronicInvoiceAccountStatus::refused('einvoicing.provider.super_pdp.missing_credentials');
        }

        [$clientId, $clientSecret] = $credentials;

        try {
            $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
            $verification = AccountVerification::tryFrom((string) ($this->client->getSession($accessToken)['company_verification_status'] ?? ''))
                ?? AccountVerification::Unknown;

            // Nothing else answers until the identity is verified.
            if (! $verification->isUsable()) {
                return new ElectronicInvoiceAccountStatus($verification);
            }

            $company = $this->client->getCompany($accessToken);
        } catch (SuperPdpApiException $e) {
            $this->logger->warning('Could not check the SUPER PDP account.', ['exception' => $e]);

            return $e->isUnauthorized()
                ? ElectronicInvoiceAccountStatus::refused('einvoicing.provider.super_pdp.bad_credentials')
                : ElectronicInvoiceAccountStatus::unreachable($e->getMessage());
        }

        return new ElectronicInvoiceAccountStatus(
            $verification,
            is_string($company['formal_name'] ?? null) ? $company['formal_name'] : null,
            is_string($company['number'] ?? null) ? $company['number'] : null,
            is_string($company['env'] ?? null) ? $company['env'] : null,
            warnings: $this->vatSettingWarnings($company),
        );
    }

    /**
     * Where the VAT settings SUPER PDP holds for the company disagree with
     * Augias's.
     *
     * @param array<string, mixed> $company
     *
     * @return list<string>
     */
    private function vatSettingWarnings(array $company): array
    {
        $warnings = [];

        if (($company['has_vat_on_debits'] ?? false) !== $this->systemConfig->isVatOnDebits()) {
            $warnings[] = 'einvoicing.account.warning.vat_on_debits';
        }

        $regime = is_string($company['vat_regime'] ?? null) ? $company['vat_regime'] : '';
        $expected = $this->expectedVatRegime();

        if ('' === $regime) {
            $warnings[] = 'einvoicing.account.warning.vat_regime_missing';
        } elseif (null !== $expected && $expected !== $regime) {
            $warnings[] = 'einvoicing.account.warning.vat_regime_mismatch';
        }

        return $warnings;
    }

    /**
     * SUPER PDP's name for the company's VAT regime, as far as Augias can
     * tell it: exempt, or the rhythm VAT is declared on. Null when that
     * rhythm is not one SUPER PDP names.
     */
    private function expectedVatRegime(): ?string
    {
        if ($this->systemConfig->isVatExempt()) {
            return 'vat_exemption';
        }

        $rhythm = trim((string) $this->systemConfig->get(SystemConfig::VAT_PERIODICITY_CONFIG_PATH));
        $rhythm = '' === $rhythm ? trim((string) $this->systemConfig->get(SystemConfig::DECLARATION_PERIODICITY_CONFIG_PATH)) : $rhythm;

        return match ($rhythm) {
            'month' => 'monthly',
            'quarter' => 'quarterly',
            default => null,
        };
    }

    /**
     * What to tell the user when SUPER PDP refuses: until the company is
     * verified, it answers 403 to everything, and its own message says
     * nothing about why.
     */
    private function forbiddenReason(string $clientId, string $clientSecret, SuperPdpApiException $e): string
    {
        if (! $e->isForbidden()) {
            return $e->getMessage();
        }

        try {
            $status = AccountVerification::tryFrom((string) ($this->client->getSession($this->client->getAccessToken($clientId, $clientSecret))['company_verification_status'] ?? ''));
        } catch (SuperPdpApiException) {
            return $e->getMessage();
        }

        return match ($status) {
            AccountVerification::NeedsReview => 'einvoicing.provider.super_pdp.needs_review',
            AccountVerification::Failed => 'einvoicing.provider.super_pdp.verification_failed',
            default => $e->getMessage(),
        };
    }

    public static function getName(): string
    {
        return 'super_pdp';
    }

    public function getForm(): string
    {
        return SuperPdpConfigType::class;
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     */
    public function send(Invoice $invoice, array $config): ElectronicInvoiceSubmissionResult
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            return ElectronicInvoiceSubmissionResult::failure('einvoicing.provider.super_pdp.missing_credentials');
        }

        [$clientId, $clientSecret] = $credentials;

        try {
            $document = $this->documentBuilder->build($invoice);
            $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
            $response = $this->client->sendInvoice($accessToken, $document, (string) $invoice->getId());
        } catch (SuperPdpApiException $e) {
            $this->logger->error('SUPER PDP rejected the invoice submission.', ['exception' => $e, 'invoice' => (string) $invoice->getId()]);

            return ElectronicInvoiceSubmissionResult::failure($this->forbiddenReason($clientId, $clientSecret, $e));
        } catch (Throwable $e) {
            $this->logger->error('Failed to build or send the Factur-X document for SUPER PDP.', ['exception' => $e, 'invoice' => (string) $invoice->getId()]);

            return ElectronicInvoiceSubmissionResult::failure('einvoicing.provider.super_pdp.build_failed');
        }

        $externalReference = $response['id'] ?? null;

        return ElectronicInvoiceSubmissionResult::success(
            is_int($externalReference) || is_string($externalReference) ? (string) $externalReference : null,
        );
    }

    public function resolveProcessingStatus(ElectronicInvoiceSubmission $submission): ElectronicInvoiceProcessingStatus
    {
        if (! $submission->isSuccess()) {
            return ElectronicInvoiceProcessingStatus::Rejected;
        }

        $statusCode = $submission->getStatusCode();

        return match (true) {
            $statusCode === null => ElectronicInvoiceProcessingStatus::Pending,
            in_array($statusCode, self::REJECTED_STATUS_CODES, true) => ElectronicInvoiceProcessingStatus::Rejected,
            in_array($statusCode, self::ACCEPTED_STATUS_CODES, true) => ElectronicInvoiceProcessingStatus::Accepted,
            default => ElectronicInvoiceProcessingStatus::Pending,
        };
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     */
    public function fetchIncoming(array $config, ?string $afterExternalReference): array
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            return [];
        }

        [$clientId, $clientSecret] = $credentials;

        try {
            $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
            $response = $this->client->listIncomingInvoices(
                $accessToken,
                $afterExternalReference !== null ? (int) $afterExternalReference : null,
            );
        } catch (SuperPdpApiException $e) {
            // Said plainly: an unverified account is refused on every route,
            // and a bare 403 in the log would not tell anyone why nothing
            // arrives.
            $this->logger->error('Failed to list incoming invoices from SUPER PDP.', [
                'exception' => $e,
                'reason' => $this->forbiddenReason($clientId, $clientSecret, $e),
            ]);

            return [];
        }

        $items = $response['data'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $results[] = $this->mapIncomingInvoice($item);
            }
        }

        return $results;
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     *
     * @throws SuperPdpApiException
     */
    public function downloadIncomingDocument(array $config, string $externalReference): DownloadedElectronicInvoiceDocument
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            throw new SuperPdpApiException('Missing SUPER PDP credentials.');
        }

        [$clientId, $clientSecret] = $credentials;

        $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
        $raw = $this->client->downloadInvoiceDocument($accessToken, $externalReference);

        $mimeType = $raw['content_type'];

        return new DownloadedElectronicInvoiceDocument(
            $raw['content'],
            $mimeType,
            str_contains($mimeType, 'pdf') ? 'pdf' : 'xml',
        );
    }

    /**
     * The `events` array of a raw `invoice_overview` API response — shared by
     * fetchIncoming() and {@see \Augias\ElectronicInvoicingBundle\Command\PollSuperPdpInvoiceStatusCommand},
     * both of which only care about the most recently reported status code.
     *
     * Ordered by `id` (SUPER PDP's own auto-incrementing, always-monotonic
     * event id per the OpenAPI spec) rather than `created_at`: two events can
     * be created microseconds apart with a *different number of fractional
     * digits* in their timestamp strings (observed: "...40.43454Z" vs
     * "...40.434541Z") — comparing those as plain strings sorts the shorter
     * one after the longer one regardless of which is actually earlier,
     * silently picking a stale status.
     */
    public static function latestStatusCode(mixed $events): ?string
    {
        if (! is_array($events) || $events === []) {
            return null;
        }

        usort($events, static fn (mixed $a, mixed $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        $latest = $events[array_key_last($events)];
        $statusCode = is_array($latest) ? $latest['status_code'] ?? null : null;

        return is_string($statusCode) ? $statusCode : null;
    }

    /**
     * @param array<string, mixed> $item one element of `GET /v1.beta/invoices`'
     *                                   `data`, with `expand[]=en_invoice` applied
     */
    private function mapIncomingInvoice(array $item): ReceivedElectronicInvoiceData
    {
        $enInvoice = is_array($item['en_invoice'] ?? null) ? $item['en_invoice'] : [];
        $seller = is_array($enInvoice['seller'] ?? null) ? $enInvoice['seller'] : [];
        $totals = is_array($enInvoice['totals'] ?? null) ? $enInvoice['totals'] : [];

        return new ReceivedElectronicInvoiceData(
            externalReference: (string) ($item['id'] ?? ''),
            invoiceNumber: is_string($enInvoice['number'] ?? null) ? $enInvoice['number'] : null,
            sellerName: is_string($seller['name'] ?? null) ? $seller['name'] : null,
            sellerIdentifier: $this->sellerIdentifier($seller),
            issueDate: $this->parseDate($enInvoice['issue_date'] ?? null),
            totalAmount: $this->toMinorUnits($totals['amount_due_for_payment'] ?? null),
            currencyCode: is_string($enInvoice['currency_code'] ?? null) ? $enInvoice['currency_code'] : null,
            statusCode: self::latestStatusCode($item['events'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $seller
     */
    private function sellerIdentifier(array $seller): ?string
    {
        $identifiers = $seller['identifiers'] ?? null;

        if (is_array($identifiers) && is_array($identifiers[0] ?? null) && is_string($identifiers[0]['value'] ?? null)) {
            return $identifiers[0]['value'];
        }

        $legal = $seller['legal_registration_identifier'] ?? null;

        return is_array($legal) && is_string($legal['value'] ?? null) ? $legal['value'] : null;
    }

    private function parseDate(mixed $date): ?DateTimeImmutable
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * EN16931 amounts are decimal strings in major units (e.g. "1234.56") — every
     * other amount in this app is minor units (cents), so this converts between
     * the two the way {@see \Augias\ElectronicInvoicingBundle\Provider\SuperPdp\FacturXInvoiceBuilder::minorToFloat()}
     * does in the opposite direction.
     */
    private function toMinorUnits(mixed $amount): ?BigNumber
    {
        if (! is_string($amount) || $amount === '') {
            return null;
        }

        try {
            return BigDecimal::of($amount)->withPointMovedRight(2)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     *
     * @return array{0: string, 1: string}|null
     */
    private function credentials(array $config): ?array
    {
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return null;
        }

        return [$clientId, $clientSecret];
    }
}
