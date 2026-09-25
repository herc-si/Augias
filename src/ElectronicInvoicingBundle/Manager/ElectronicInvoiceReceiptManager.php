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
use Augias\ElectronicInvoicingBundle\Enum\RefusalReason;
use Augias\ElectronicInvoicingBundle\Event\ElectronicInvoiceReceiptImportedEvent;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceResponderInterface;
use Augias\ElectronicInvoicingBundle\Provider\ReceivedElectronicInvoiceData;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;
use function sprintf;
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
    public function __construct(
        private ElectronicInvoiceProviderRegistry $registry,
        private ElectronicInvoiceProviderSettingRepository $settingRepository,
        private ElectronicInvoiceReceiptRepository $receiptRepository,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        private string $projectDir,
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
        return ! $receipt->getResponse() instanceof ReceiptResponse
            && $this->responder($receipt) !== null;
    }

    public function respond(ElectronicInvoiceReceipt $receipt, ReceiptResponse $response, ?RefusalReason $reason = null, ?string $comment = null): void
    {
        if ($receipt->getResponse() instanceof ReceiptResponse) {
            throw new RuntimeException('einvoicing.response.already_answered');
        }

        if ($response->needsReason() && ! $reason instanceof RefusalReason) {
            throw new RuntimeException('einvoicing.response.reason_required');
        }

        // A reason belongs to a refusal only.
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
        $receipt->setStatusCode($response->value);
        $this->entityManager->flush();
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
     * @return string the path relative to $projectDir, stored on the entity
     */
    private function storeDocument(Company $company, ElectronicInvoiceReceipt $receipt, string $content, string $fileExtension): string
    {
        $relativePath = sprintf(
            'var/einvoicing/incoming/%s/%s.%s',
            $company->getId()->toBase58(),
            $receipt->getExternalReference(),
            $fileExtension,
        );

        $this->filesystem->dumpFile($this->projectDir . '/' . $relativePath, $content);

        return $relativePath;
    }
}
