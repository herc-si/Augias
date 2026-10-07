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

use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Event\ElectronicInvoiceReceiptAnsweredEvent;
use Augias\ElectronicInvoicingBundle\Event\ElectronicInvoiceReceiptImportedEvent;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceResponderInterface;
use Augias\ElectronicInvoicingBundle\Provider\ReceivedElectronicInvoiceData;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;
use function is_file;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Central place for "can this company receive electronic invoices, and doing
 * so" — the inbound counterpart of {@see \Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager},
 * shared by the scheduled import command and (eventually) any manual
 * "check for new invoices now" action, so eligibility and import bookkeeping
 * stay in one place.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Manager\ElectronicInvoiceReceiptManagerTest
 */
final readonly class ElectronicInvoiceReceiptManager implements ElectronicInvoiceReceiptManagerInterface
{
    /**
     * Where documents went before 06/10/2026, relative to the project: inside
     * whichever container imported them.
     */
    private const string LEGACY_DIRECTORY = 'var/einvoicing/incoming';

    public function __construct(
        private ElectronicInvoiceProviderRegistry $registry,
        private ElectronicInvoiceProviderSettingRepository $settingRepository,
        private ElectronicInvoiceReceiptRepository $receiptRepository,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        private string $projectDir,
        #[Autowire(env: 'AUGIAS_ATTACHMENTS_DIR')]
        private string $attachmentsDir,
    ) {
    }

    public function isReceivingEnabled(Company $company): bool
    {
        $setting = $this->activeReceiverSetting($company);

        return $setting !== null && $this->registry->getReceiver($setting->getProvider()) !== null;
    }

    public function importNew(Company $company): array
    {
        $setting = $this->activeReceiverSetting($company);

        if ($setting === null) {
            return [];
        }

        $receiver = $this->registry->getReceiver($setting->getProvider());

        if ($receiver === null) {
            return [];
        }

        $companyId = $company->getId();
        $cursor = $this->receiptRepository->findLatestExternalReference($companyId, $setting->getProvider());
        $config = $setting->getSettings();

        $imported = [];

        foreach ($receiver->fetchIncoming($config, $cursor) as $data) {
            // Defensive: fetchIncoming() is contracted to return only invoices
            // newer than the cursor, but a provider bug or a re-run with a stale
            // cursor must never produce a duplicate row (the unique constraint
            // would reject it mid-flush anyway, aborting the whole batch).
            if ($this->receiptRepository->existsForExternalReference($companyId, $setting->getProvider(), $data->externalReference)) {
                continue;
            }

            $receipt = $this->createReceipt($company, $setting->getProvider(), $data);

            try {
                $document = $receiver->downloadIncomingDocument($config, $data->externalReference);
                $receipt->setDocumentPath($this->storeDocument($company, $receipt, $document->content, $document->fileExtension));
                $receipt->setDocumentMimeType($document->mimeType);
            } catch (Throwable $e) {
                // The invoice metadata itself is still worth keeping even if the
                // document couldn't be fetched this time — it stays downloadable
                // later once whatever failed (network, provider outage) recovers,
                // since hasDocument() on the entity reflects the real state.
                $this->logger->error('Failed to download incoming electronic invoice document', [
                    'company_id' => (string) $companyId,
                    'provider' => $setting->getProvider(),
                    'external_reference' => $data->externalReference,
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->entityManager->persist($receipt);
            $imported[] = $receipt;
        }

        if ($imported !== []) {
            $this->entityManager->flush();

            // After the flush so listeners see persisted receipts with ids.
            foreach ($imported as $receipt) {
                $this->eventDispatcher->dispatch(new ElectronicInvoiceReceiptImportedEvent($receipt));
            }
        }

        return $imported;
    }

    /**
     * Whichever provider is in use for this specific company — imports run
     * across every company with the filter disabled, so the company is given
     * rather than read from the request. A provider whose platform has not
     * verified the company is not in use, and nothing is fetched through it.
     */
    public function canRespond(ElectronicInvoiceReceipt $receipt): bool
    {
        return true !== $receipt->getResponse()?->isFinal()
            && $this->responder($receipt) !== null;
    }

    public function respond(ElectronicInvoiceReceipt $receipt, ReceiptResponse $response, ?ResponseReason $reason = null, ?string $comment = null): void
    {
        // A dispute leaves the invoice open, for an acceptance or a refusal
        // once it is settled — not for a second dispute.
        $previous = $receipt->getResponse();

        if ($previous instanceof ReceiptResponse && ($previous->isFinal() || $previous === $response)) {
            throw new RuntimeException('einvoicing.response.already_answered');
        }

        if ($response->needsReason() && ! $reason instanceof ResponseReason) {
            throw new RuntimeException('einvoicing.response.reason_required');
        }

        if ($response->needsReason() && ! $reason?->isAllowedFor($response)) {
            throw new RuntimeException('einvoicing.response.reason_not_allowed');
        }

        // A reason belongs to a refusal or a dispute only.
        $reason = $response->needsReason() ? $reason : null;
        $comment = null === $comment ? null : trim($comment);

        $responder = $this->responder($receipt);

        if (null === $responder) {
            throw new RuntimeException('einvoicing.response.unavailable');
        }

        [$provider, $setting] = $responder;
        $provider->respond($setting->getSettings(), $receipt->getExternalReference(), $response, $reason, '' === $comment ? null : $comment);

        // Recorded only once the platform took it: an answer the supplier
        // never received is not an answer.
        $receipt->recordResponse($response, $reason, '' === $comment ? null : $comment, new DateTimeImmutable());
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new ElectronicInvoiceReceiptAnsweredEvent($receipt, $response));
    }

    /**
     * The provider the invoice came through, when it is in use and can carry
     * an answer back — through the same platform, since that is where the
     * supplier's invoice lives.
     *
     * @return array{0: ElectronicInvoiceResponderInterface, 1: ElectronicInvoiceProviderSetting}|null
     */
    private function responder(ElectronicInvoiceReceipt $receipt): ?array
    {
        $setting = $this->settingRepository->findActiveForCompany($receipt->getCompany()->getId(), $receipt->getProvider());
        $provider = $setting instanceof ElectronicInvoiceProviderSetting ? $this->registry->get($setting->getProvider()) : null;

        return $provider instanceof ElectronicInvoiceResponderInterface && $setting instanceof ElectronicInvoiceProviderSetting
            ? [$provider, $setting]
            : null;
    }

    private function activeReceiverSetting(Company $company): ?ElectronicInvoiceProviderSetting
    {
        return $this->settingRepository->findActiveForCompany($company->getId());
    }

    private function createReceipt(Company $company, string $provider, ReceivedElectronicInvoiceData $data): ElectronicInvoiceReceipt
    {
        $receipt = new ElectronicInvoiceReceipt();
        $receipt->setCompany($company)
            ->setProvider($provider)
            ->setExternalReference($data->externalReference)
            ->setInvoiceNumber($data->invoiceNumber)
            ->setSellerName($data->sellerName)
            ->setSellerIdentifier($data->sellerIdentifier)
            ->setIssueDate($data->issueDate)
            ->setTotalAmount($data->totalAmount)
            ->setCurrencyCode($data->currencyCode)
            ->setStatusCode($data->statusCode)
            ->setTaxAmount($data->taxAmount)
            ->setSupplyType($data->supplyType)
            ->setSupplierVatOnDebits($data->supplierVatOnDebits);

        return $receipt;
    }

    /**
     * The document of a received invoice on disk, fetched again from the
     * platform when it is not there — null when it cannot be had.
     *
     * Not there: imported by the cron container into a directory of its own,
     * which the web container never saw and a redeploy wiped (test instance,
     * 06/10/2026). Fetched again, it goes where both containers look.
     */
    public function documentFile(ElectronicInvoiceReceipt $receipt): ?string
    {
        $stored = $receipt->getDocumentPath();

        if (null !== $stored && ! str_contains($stored, '..')) {
            $absolute = str_starts_with($stored, self::LEGACY_DIRECTORY . '/')
                ? $this->projectDir . '/' . $stored
                : $this->attachmentsDir . '/' . $stored;

            if (is_file($absolute)) {
                return $absolute;
            }
        }

        $company = $receipt->getCompany();
        $setting = $this->activeReceiverSetting($company);
        $receiver = null === $setting ? null : $this->registry->getReceiver($setting->getProvider());

        if (null === $setting || null === $receiver || $setting->getProvider() !== $receipt->getProvider()) {
            return null;
        }

        try {
            $document = $receiver->downloadIncomingDocument($setting->getSettings(), $receipt->getExternalReference());
        } catch (Throwable $e) {
            $this->logger->error('Failed to download incoming electronic invoice document again', [
                'receipt_id' => (string) $receipt->getId(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $receipt->setDocumentPath($this->storeDocument($company, $receipt, $document->content, $document->fileExtension));
        $receipt->setDocumentMimeType($document->mimeType);
        $this->entityManager->flush();

        return $this->attachmentsDir . '/' . $receipt->getDocumentPath();
    }

    /**
     * @return string the path relative to the attachments directory, stored on the entity
     */
    private function storeDocument(Company $company, ElectronicInvoiceReceipt $receipt, string $content, string $fileExtension): string
    {
        // With the supporting documents: on the volume every container shares,
        // and that outlives them.
        $relativePath = sprintf(
            'einvoicing/incoming/%s/%s.%s',
            $company->getId()->toBase58(),
            $receipt->getExternalReference(),
            $fileExtension,
        );

        $this->filesystem->dumpFile($this->attachmentsDir . '/' . $relativePath, $content);

        return $relativePath;
    }
}
