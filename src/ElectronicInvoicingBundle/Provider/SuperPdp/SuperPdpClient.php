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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Thin wrapper around SUPER PDP's REST API (https://api.superpdp.tech,
 * documented at https://www.superpdp.tech/openapi/#superpdp).
 *
 * Auth is OAuth2 client_credentials: a fresh token is requested for every
 * call rather than cached, since electronic-invoice submissions/status
 * checks are low-frequency operations for a self-hosted invoicing app.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\SuperPdpClientTest
 */
final readonly class SuperPdpClient
{
    private const string BASE_URL = 'https://api.superpdp.tech';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @throws SuperPdpApiException
     */
    public function getAccessToken(string $clientId, string $clientSecret): string
    {
        $response = $this->request('POST', '/oauth2/token', [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new SuperPdpApiException('SUPER PDP did not return an access token.');
        }

        return $token;
    }

    /**
     * Sends a lifecycle status for an invoice — for a received one, the
     * buyer's answer: fr:205 accepted, fr:210 refused. SUPER PDP queues it
     * and carries it to the supplier's platform.
     *
     * @return array<string, mixed>
     *
     * @throws SuperPdpApiException
     */
    public function createInvoiceEvent(string $accessToken, int $invoiceId, string $statusCode, ?string $reasonCode = null, ?string $note = null): array
    {
        $payload = ['invoice_id' => $invoiceId, 'status_code' => $statusCode];
        $detail = [];

        // MDT-113, a normalised code; MDT-114 and notes carry the words.
        if (null !== $reasonCode) {
            $detail['reason'] = $reasonCode;
        }

        if (null !== $note && '' !== $note) {
            $detail['notes'] = [['contents' => [['content' => $note]]]];
        }

        if ([] !== $detail) {
            $payload['details'] = [$detail];
        }

        return $this->request('POST', '/v1.beta/invoice_events', [
            'auth_bearer' => $accessToken,
            'json' => $payload,
        ]);
    }

    /**
     * The e-invoicing addresses registered for the company behind the
     * credentials, e.g. "0225:315143296_92568".
     *
     * @return list<string>
     *
     * @throws SuperPdpApiException
     */
    public function getOwnAddresses(string $accessToken): array
    {
        return $this->identifiers($this->request('GET', '/v1.beta/directory_entries', ['auth_bearer' => $accessToken]));
    }

    /**
     * The e-invoicing addresses the French directory lists for a SIREN — where
     * an invoice to that company can be delivered. Empty for a company the
     * directory does not know, and for every sandbox company.
     *
     * @return list<string>
     *
     * @throws SuperPdpApiException
     */
    public function findAddresses(string $accessToken, string $siren): array
    {
        return $this->identifiers($this->request('GET', '/v1.beta/french_directory/entries', [
            'auth_bearer' => $accessToken,
            'query' => ['number' => $siren],
        ]));
    }

    /**
     * @param array<string, mixed> $listing
     *
     * @return list<string>
     */
    private function identifiers(array $listing): array
    {
        $identifiers = [];

        foreach (is_array($listing['data'] ?? null) ? $listing['data'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['identifier'] ?? null) && '' !== $entry['identifier']) {
                $identifiers[] = $entry['identifier'];
            }
        }

        return $identifiers;
    }

    /**
     * The current OAuth2 session, notably `company_verification_status`:
     * `verified`, `needs_review` or `failed`. The one route that still
     * answers while the company is not verified — every other returns 403.
     *
     * @return array<string, mixed>
     *
     * @throws SuperPdpApiException
     */
    public function getSession(string $accessToken): array
    {
        return $this->request('GET', '/v1.beta/oauth2_sessions/me', [
            'auth_bearer' => $accessToken,
        ]);
    }

    /**
     * The company behind the credentials: its environment (`sandbox` or
     * `production`), identity, VAT regime and option for VAT on debits.
     *
     * @return array<string, mixed>
     *
     * @throws SuperPdpApiException
     */
    public function getCompany(string $accessToken): array
    {
        return $this->request('GET', '/v1.beta/companies/me', [
            'auth_bearer' => $accessToken,
        ]);
    }

    /**
     * Uploads a Factur-X invoice (or CII/UBL XML) for asynchronous processing.
     *
     * $processingRule is left null by default: SUPER PDP computes it (B2B, B2C,
     * B2BInt, ...) from the document itself, and only errors out if we pass a
     * value that disagrees with what it computed — since we can't reliably
     * predict that computation ourselves (it depends on directory lookups on
     * their end), it's safer to let them decide than to force one.
     *
     * @return array<string, mixed> the decoded invoice_overview response, notably its `id`
     *
     * @throws SuperPdpApiException
     */
    public function sendInvoice(string $accessToken, string $documentContent, string $externalId, ?string $processingRule = null): array
    {
        $query = ['external_id' => $externalId];

        if ($processingRule !== null) {
            $query['processing_rule'] = $processingRule;
        }

        return $this->request('POST', '/v1.beta/invoices', [
            'auth_bearer' => $accessToken,
            'headers' => ['Content-Type' => 'application/pdf'],
            'query' => $query,
            'body' => $documentContent,
        ]);
    }

    /**
     * @return array<string, mixed> the decoded invoice_overview response, notably its `events`
     *
     * @throws SuperPdpApiException
     */
    public function getInvoice(string $accessToken, string $superPdpInvoiceId): array
    {
        return $this->request('GET', '/v1.beta/invoices/' . $superPdpInvoiceId, [
            'auth_bearer' => $accessToken,
        ]);
    }

    /**
     * Lists invoices received BY this company (`direction: in`), oldest first, with
     * their `en_invoice` data expanded so the caller doesn't need a second request
     * per invoice. $startingAfterId (SUPER PDP's own incrementing invoice id, not
     * our external_reference) resumes a previous listing — null starts from the
     * beginning.
     *
     * @return array<string, mixed> the decoded list_invoices response
     *
     * @throws SuperPdpApiException
     */
    public function listIncomingInvoices(string $accessToken, ?int $startingAfterId = null): array
    {
        $query = [
            'direction' => 'in',
            'order' => 'asc',
            // en_invoice's `seller` (unlike `totals`, always included) is one of
            // the fields the API docs call out as optional on the overview shape
            // and comes back null unless separately expanded — confirmed against
            // a real received invoice during testing: `expand[]=en_invoice` alone
            // silently omitted the seller identity entirely.
            'expand' => ['en_invoice', 'en_invoice.seller'],
        ];

        if ($startingAfterId !== null) {
            $query['starting_after_id'] = $startingAfterId;
        }

        return $this->request('GET', '/v1.beta/invoices', [
            'auth_bearer' => $accessToken,
            'query' => $query,
        ]);
    }

    /**
     * Downloads the human-readable Factur-X PDF for a received invoice.
     *
     * @return array{content: string, content_type: string}
     *
     * @throws SuperPdpApiException
     */
    public function downloadInvoiceDocument(string $accessToken, string $superPdpInvoiceId): array
    {
        return $this->requestRaw('GET', '/v1.beta/invoices/' . $superPdpInvoiceId, [
            'auth_bearer' => $accessToken,
            'query' => ['format' => 'factur-x'],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws SuperPdpApiException
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);

            // Default $throw=true: a 4xx/5xx must raise HttpExceptionInterface here so
            // extractError() below can surface the API's http_ko message, instead of
            // silently returning its error body as if it were a successful response.
            return $response->toArray();
        } catch (TransportException $e) {
            throw new SuperPdpApiException('Could not reach the SUPER PDP API: ' . $e->getMessage(), previous: $e);
        } catch (ExceptionInterface $e) {
            [$message, $code] = $this->extractError($e);

            throw new SuperPdpApiException($message, $code, $e, $e instanceof HttpExceptionInterface ? $e->getResponse()->getStatusCode() : null);
        }
    }

    /**
     * Same error handling as request(), for endpoints returning a raw binary
     * body (a PDF/XML document) rather than JSON.
     *
     * @param array<string, mixed> $options
     *
     * @return array{content: string, content_type: string}
     *
     * @throws SuperPdpApiException
     */
    private function requestRaw(string $method, string $path, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);

            return [
                'content' => $response->getContent(),
                'content_type' => $response->getHeaders()['content-type'][0] ?? 'application/octet-stream',
            ];
        } catch (TransportException $e) {
            throw new SuperPdpApiException('Could not reach the SUPER PDP API: ' . $e->getMessage(), previous: $e);
        } catch (ExceptionInterface $e) {
            [$message, $code] = $this->extractError($e);

            throw new SuperPdpApiException($message, $code, $e, $e instanceof HttpExceptionInterface ? $e->getResponse()->getStatusCode() : null);
        }
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function extractError(ExceptionInterface $e): array
    {
        if (! $e instanceof HttpExceptionInterface) {
            return [$e->getMessage(), null];
        }

        try {
            $content = $e->getResponse()->toArray(false);
        } catch (Throwable) {
            return [$e->getMessage(), null];
        }

        $message = is_string($content['message'] ?? null) ? $content['message'] : $e->getMessage();
        $code = is_int($content['code'] ?? null) ? $content['code'] : null;

        return [$code !== null ? sprintf('%s [%d]', $message, $code) : $message, $code];
    }
}
