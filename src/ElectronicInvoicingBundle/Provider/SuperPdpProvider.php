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

use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\RefusalReason;
use Augias\ElectronicInvoicingBundle\Form\Type\Provider\SuperPdpConfigType;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\ElectronicAddressResolver;
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
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_values;
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
final readonly class SuperPdpProvider implements ElectronicInvoiceProviderInterface, ElectronicInvoiceReceiverInterface, ElectronicInvoiceAccountCheckerInterface, ElectronicInvoiceResponderInterface, ElectronicReporterInterface
{
    /**
     * BT-8 values meaning the supplier's VAT falls due on the invoice date —
     * its option for debits. SUPER PDP reports "3" (UNTDID 2005, the semantic
     * model's list) for what a CII invoice carries as "5" (UNTDID 2475).
     *
     * @var list<string>
     */
    private const array VAT_ON_INVOICE_DATE_CODES = ['3', '5'];

    /**
     * `fr:*` codes per https://api.superpdp.tech/openapi/superpdp.json that mean
     * the invoice reached its recipient without being refused (fr:205 Accepted,
     * fr:206 Partly accepted, fr:209 Completed), or was paid (fr:211 Payment
     * sent, fr:212 Payment received — which Augias sends itself, and can come
     * back as the latest status before any acceptance does) — the single
     * source of truth for this, also consumed by {@see \Augias\ElectronicInvoicingBundle\Command\PollSuperPdpInvoiceStatusCommand}.
     */
    public const array ACCEPTED_STATUS_CODES = ['fr:205', 'fr:206', 'fr:209', 'fr:211', 'fr:212'];

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
        private ElectronicAddressResolver $addressResolver,
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
            $accessToken = $this->client->getAccessToken($clientId, $clientSecret);
            // Where the invoice goes, and where answers come back to — found
            // in the directory when nobody typed it. Before building, since
            // the e-invoice carries both addresses.
            $this->addressResolver->resolve($accessToken, $invoice->getCompany(), $invoice->getClient());
            $document = $this->documentBuilder->build($invoice);
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
            taxAmount: $this->toMinorUnits(is_array($totals['total_vat_amount'] ?? null) ? ($totals['total_vat_amount']['value'] ?? null) : null),
            supplyType: $this->supplyType($enInvoice),
            supplierVatOnDebits: in_array($enInvoice['vat_point_date_code'] ?? null, self::VAT_ON_INVOICE_DATE_CODES, true),
        );
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     *
     * @throws SuperPdpApiException
     */
    public function respond(array $config, string $externalReference, ReceiptResponse $response, ?RefusalReason $reason = null, ?string $comment = null): void
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            throw new SuperPdpApiException('einvoicing.provider.super_pdp.missing_credentials');
        }

        [$clientId, $clientSecret] = $credentials;

        try {
            $this->client->createInvoiceEvent(
                $this->client->getAccessToken($clientId, $clientSecret),
                (int) $externalReference,
                $response->value,
                $reason?->value,
                $comment,
            );
        } catch (SuperPdpApiException $e) {
            $this->logger->error('SUPER PDP did not take the answer to a received invoice.', ['exception' => $e, 'invoice' => $externalReference]);

            throw new SuperPdpApiException($this->forbiddenReason($clientId, $clientSecret, $e), $e->getApiCode(), $e);
        }
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     * @param list<ReportedTransaction>                      $transactions
     *
     * @throws SuperPdpApiException
     */
    public function reportTransactions(array $config, array $transactions): array
    {
        return $this->report($config, fn (string $token): array => $this->client->createB2cTransactions($token, array_map(
            static fn (ReportedTransaction $transaction): array => array_filter([
                'date' => $transaction->date->format('Y-m-d'),
                'currency' => $transaction->currency,
                'category_code' => $transaction->category,
                'role_code' => 'SE',
                'tax_exclusive_amount' => $transaction->taxExclusiveAmount,
                'tax_total' => $transaction->taxTotal,
                'tax_subtotals' => array_map(
                    static fn (string $rate, array $subtotal): array => ['tax_percent' => $rate, 'taxable_amount' => $subtotal['taxable'], 'tax_total' => $subtotal['tax']],
                    array_keys($transaction->subtotals),
                    array_values($transaction->subtotals),
                ),
                'tax_due_date_type_code' => $transaction->taxDueDateTypeCode,
            ], static fn (mixed $value): bool => null !== $value),
            $transactions,
        )));
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     * @param list<ReportedPayment>                          $payments
     *
     * @throws SuperPdpApiException
     */
    public function reportPayments(array $config, array $payments): array
    {
        return $this->report($config, fn (string $token): array => $this->client->createB2cPayments($token, array_map(
            static fn (ReportedPayment $payment): array => [
                'date' => $payment->date->format('Y-m-d'),
                'subtotals' => array_map(
                    static fn (string $rate, string $amount): array => ['tax_percent' => $rate, 'amount' => $amount, 'currency_code' => $payment->currency],
                    array_keys($payment->amounts),
                    array_values($payment->amounts),
                ),
            ],
            $payments,
        )));
    }

    /**
     * fr:212 with the amount received by VAT rate ("MEN", tax included).
     * Once per payment: a second one on the same invoice adds to the first.
     *
     * @param array{client_id?: mixed, client_secret?: mixed} $config
     *
     * @throws SuperPdpApiException
     */
    public function reportPaymentReceived(array $config, string $invoiceReference, ReportedPayment $payment): array
    {
        return $this->report($config, fn (string $token): array => ['data' => [$this->client->createInvoiceEvent(
            $token,
            (int) $invoiceReference,
            'fr:212',
            reportedData: array_map(
                static fn (string $rate, string $amount): array => [
                    'type_code' => 'MEN',
                    'amount' => $amount,
                    'currency_code' => $payment->currency,
                    'date' => $payment->date->format('Y-m-d'),
                    'value_percent' => $rate,
                ],
                array_keys($payment->amounts),
                array_values($payment->amounts),
            ),
        )]]);
    }

    /**
     * @param array{client_id?: mixed, client_secret?: mixed}   $config
     * @param callable(string): array<string, mixed>            $call
     *
     * @return list<string>
     *
     * @throws SuperPdpApiException
     */
    private function report(array $config, callable $call): array
    {
        $credentials = $this->credentials($config);

        if ($credentials === null) {
            throw new SuperPdpApiException('einvoicing.provider.super_pdp.missing_credentials');
        }

        [$clientId, $clientSecret] = $credentials;

        try {
            $response = $call($this->client->getAccessToken($clientId, $clientSecret));
        } catch (SuperPdpApiException $e) {
            $this->logger->error('SUPER PDP did not take the e-reporting data.', ['exception' => $e]);

            throw new SuperPdpApiException($this->forbiddenReason($clientId, $clientSecret, $e), $e->getApiCode(), $e);
        }

        $ids = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $stored) {
            if (is_array($stored) && (is_int($stored['id'] ?? null) || is_string($stored['id'] ?? null))) {
                $ids[] = (string) $stored['id'];
            }
        }

        return $ids;
    }

    /**
     * Goods or services, from the billing framework code (BT-23): "B…" is
     * goods, "S…" services. "M…" — both — has no single answer and leaves it
     * to the bill's default, services, whose VAT is deducted on payment: the
     * later of the two dates, and so never too early.
     *
     * @param array<string, mixed> $enInvoice
     */
    private function supplyType(array $enInvoice): ?SupplyType
    {
        $control = is_array($enInvoice['process_control'] ?? null) ? $enInvoice['process_control'] : [];
        $code = is_string($control['business_process_type'] ?? null) ? $control['business_process_type'] : '';

        return match ($code[0] ?? '') {
            'B' => SupplyType::Goods,
            'S' => SupplyType::Services,
            default => null,
        };
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
