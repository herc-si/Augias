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

namespace Augias\ElectronicInvoicingBundle\Entity;

use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One historical record of an invoice being sent to an electronic-invoicing
 * provider (success or failure) — the equivalent of a Payment attempt, but
 * for e-invoice transmission rather than money capture.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Entity\ElectronicInvoiceSubmissionTest
 */
#[ORM\Entity(repositoryClass: ElectronicInvoiceSubmissionRepository::class)]
#[ORM\Table(name: ElectronicInvoiceSubmission::TABLE_NAME)]
#[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'company', inversedBy: 'electronicInvoiceSubmissions')])]
class ElectronicInvoiceSubmission
{
    public const string TABLE_NAME = 'einvoicing_submission';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * The document sent: an invoice, or a credit note (one or the other).
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'electronicInvoiceSubmissions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: CreditNote::class, inversedBy: 'electronicInvoiceSubmissions')]
    #[ORM\JoinColumn(name: 'credit_note_id', nullable: true, onDelete: 'CASCADE')]
    private ?CreditNote $creditNote = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $provider;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success;

    #[ORM\Column(name: 'external_reference', type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /**
     * The provider's latest known processing status for this submission (e.g.
     * SUPER PDP's `fr:205`/`fr:210` codes), refreshed by a polling command
     * since not every provider exposes webhooks. Null for providers that
     * don't report an asynchronous status, or before the first poll.
     */
    #[ORM\Column(name: 'status_code', type: Types::STRING, length: 64, nullable: true)]
    private ?string $statusCode = null;

    /**
     * @var Collection<int, ElectronicInvoiceSubmissionEvent>
     */
    #[ORM\OneToMany(targetEntity: ElectronicInvoiceSubmissionEvent::class, mappedBy: 'submission', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'ASC', 'providerEventId' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getCreditNote(): ?CreditNote
    {
        return $this->creditNote;
    }

    public function setCreditNote(CreditNote $creditNote): self
    {
        $this->creditNote = $creditNote;

        return $this;
    }

    /**
     * The document this submission sent, whichever kind it is.
     */
    public function getDocument(): Invoice | CreditNote
    {
        return $this->invoice ?? $this->creditNote ?? throw new LogicException('An electronic invoicing submission sends an invoice or a credit note.');
    }

    /**
     * Its number, as the client and the platform know it.
     */
    public function getDocumentNumber(): string
    {
        $document = $this->getDocument();

        return $document instanceof Invoice ? $document->getInvoiceId() : $document->getCreditNoteId();
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;

        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getStatusCode(): ?string
    {
        return $this->statusCode;
    }

    public function setStatusCode(?string $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * @return Collection<int, ElectronicInvoiceSubmissionEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Records a step of the invoice's life, unless it already is — a poll
     * reads every event each time. True when it was new.
     */
    public function recordEvent(string $providerEventId, string $statusCode, DateTimeImmutable $occurredAt, ?string $reason = null, ?string $note = null): bool
    {
        foreach ($this->events as $event) {
            if ($event->getProviderEventId() === $providerEventId) {
                return false;
            }
        }

        $this->events->add(new ElectronicInvoiceSubmissionEvent($this, $providerEventId, $statusCode, $occurredAt, $reason, $note));

        return true;
    }
}
