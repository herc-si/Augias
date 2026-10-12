<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\QuoteBundle\DTO;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Form\InlineClient;
use Augias\ClientBundle\Validator\Constraints\InlineClientRules;
use Augias\ClientBundle\Validator\Constraints\UniqueClientName;
use Augias\CoreBundle\Entity\Discount;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Enum\QuoteClientMode;
use Augias\TaxBundle\Entity\InvoiceTax;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO for Quote form data
 */
#[InlineClientRules(groups: ['new_client'])]
final class QuoteFormDTO implements InlineClient
{
    public QuoteClientMode $clientMode = QuoteClientMode::Existing;

    // Existing client selection (mode=Existing)
    #[Assert\NotBlank(groups: ['existing_client'])]
    public ?Client $client = null;

    // Inline client fields (mode=New)
    #[Assert\NotBlank(groups: ['new_client'])]
    #[Assert\Length(max: 125, groups: ['new_client'])]
    #[UniqueClientName(groups: ['new_client'])]
    public ?string $newClientName = null;

    // A business unless said otherwise, as on the client form.
    public bool $newClientIsCompany = true;

    #[Assert\Length(max: 14, groups: ['new_client'])]
    public ?string $newClientSiret = null;

    #[Assert\NotBlank(groups: ['new_client'])]
    #[Assert\Length(max: 125, groups: ['new_client'])]
    public ?string $newContactFirstName = null;

    #[Assert\Length(max: 125, groups: ['new_client'])]
    public ?string $newContactLastName = null;

    #[Assert\NotBlank(groups: ['new_client'])]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, groups: ['new_client'])]
    public ?string $newContactEmail = null;

    // Quote entity fields
    #[Assert\NotBlank]
    public string $quoteId = '';

    #[Assert\Type(DateTimeInterface::class)]
    public ?DateTimeInterface $due = null;

    /**
     * Assigned in the constructor rather than left null so the default type
     * travels with the component's initial state. Left null, the form had
     * nothing to read a type from and published `discount: {type: ""}` in the
     * live props; the browser's select showed "%" because that is its first
     * option, but the state sent back said empty, and Discount::setValue() had
     * no branch for that — so the amount was dropped and every quote discount
     * came out zero.
     */
    public ?Discount $discount = null;

    /** What the document is about, in a few words. */
    #[Assert\Length(max: 255)]
    public ?string $subject = null;

    public ?string $terms = null;

    public ?string $notes = null;

    public ?string $total = '0';

    public ?string $baseTotal = '0';

    public ?string $tax = '0';

    /**
     * @var Collection<int, Line>
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1)]
    public Collection $lines;

    /**
     * @var Collection<int, Contact>
     */
    #[Assert\Count(min: 1, groups: ['existing_client'])]
    public Collection $users;

    /**
     * @var Collection<int, InvoiceTax>
     */
    #[Assert\Valid]
    public Collection $invoiceTaxes;

    public function __construct()
    {
        $this->discount = new Discount();
        $this->lines = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->invoiceTaxes = new ArrayCollection();
    }

    /**
     * Returns the resolved client (from existing client or null for new client mode)
     */
    public function getResolvedClient(): ?Client
    {
        return $this->clientMode === QuoteClientMode::Existing ? $this->client : null;
    }

    /**
     * Checks if all required inline client data is filled
     */
    public function hasInlineClientData(): bool
    {
        return $this->newClientName !== null
            && $this->newContactFirstName !== null
            && $this->newContactEmail !== null;
    }

    public function isNewClient(): bool
    {
        return $this->clientMode === QuoteClientMode::NewClient;
    }

    public function isNewClientCompany(): bool
    {
        return $this->newClientIsCompany;
    }

    public function getNewClientSiret(): ?string
    {
        return $this->newClientSiret;
    }
}
