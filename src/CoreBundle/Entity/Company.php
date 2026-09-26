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

namespace Augias\CoreBundle\Entity;

use const PHP_URL_HOST;
use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Entity\Credit;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\InvoiceReminder;
use Augias\InvoiceBundle\Entity\Line as InvoieLine;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Entity\UserNotification;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\QuoteBundle\Entity\Line as QuoteLine;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SettingsBundle\Entity\Setting;
use Augias\TaxBundle\Entity\Tax;
use Augias\UserBundle\Entity\ApiToken;
use Augias\UserBundle\Entity\ApiTokenHistory;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Entity\UserInvitation;
use Augias\UserBundle\Enum\CompanyRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;
use function function_exists;
use function idn_to_ascii;
use function is_string;
use function parse_url;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

#[ORM\Table(name: Company::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[UniqueEntity(fields: ['customDomain'], ignoreNull: true)]
class Company implements Stringable, SubscribableInterface
{
    final public const string TABLE_NAME = 'companies';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank()]
    #[Assert\Length(max: 45, maxMessage: 'The company name cannot be longer than {{ limit }} characters.')]
    private string $name;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'company', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    #[Assert\NotBlank()]
    public ?string $currency = '';

    #[ORM\Column(name: 'custom_domain', type: Types::STRING, length: 253, unique: true, nullable: true)]
    #[Assert\Length(max: 253)]
    #[Assert\Hostname(requireTld: true)]
    private ?string $customDomain = null;

    // Related entities: Only added here to enable orphan removal
    /**
     * @var Collection<int, ApiTokenHistory>
     */
    #[ORM\OneToMany(targetEntity: ApiTokenHistory::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $apiTokenHistories;

    /**
     * @var Collection<int, Tax>
     */
    #[ORM\OneToMany(targetEntity: Tax::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $taxes;

    /**
     * @var Collection<int, Address>
     */
    #[ORM\OneToMany(targetEntity: Address::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $addresses;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $clients;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $contacts;

    /**
     * @var Collection<int, Credit>
     */
    #[ORM\OneToMany(targetEntity: Credit::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $credit;

    /**
     * @var Collection<int, UserInvitation>
     */
    #[ORM\OneToMany(targetEntity: UserInvitation::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $userInvitations;

    /**
     * @var Collection<int, ApiToken>
     */
    #[ORM\OneToMany(targetEntity: ApiToken::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $apiTokens;

    /**
     * @var Collection<int, Setting>
     */
    #[ORM\OneToMany(targetEntity: Setting::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $settings;

    /**
     * @var Collection<int, Quote>
     */
    #[ORM\OneToMany(targetEntity: Quote::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $quotes;

    /**
     * @var Collection<int, QuoteLine>
     */
    #[ORM\OneToMany(targetEntity: QuoteLine::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $quoteLines;

    /**
     * @var Collection<int, PaymentMethod>
     */
    #[ORM\OneToMany(targetEntity: PaymentMethod::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $paymentMethods;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $payments;

    /**
     * @var Collection<int, UserNotification>
     */
    #[ORM\OneToMany(targetEntity: UserNotification::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $userNotifications;

    /**
     * @var Collection<int, TransportSetting>
     */
    #[ORM\OneToMany(targetEntity: TransportSetting::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $transportSettings;

    /**
     * @var Collection<int, Invoice>
     */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoices;

    /**
     * @var Collection<int, CreditNoteAllocation>
     */
    #[ORM\OneToMany(targetEntity: CreditNoteAllocation::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $creditNoteAllocations;

    /**
     * @var Collection<int, CreditNote>
     */
    #[ORM\OneToMany(targetEntity: CreditNote::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $creditNotes;

    /**
     * @var Collection<int, RecurringInvoice>
     */
    #[ORM\OneToMany(targetEntity: RecurringInvoice::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $recurringInvoices;

    /**
     * @var Collection<int, InvoieLine>
     */
    #[ORM\OneToMany(targetEntity: InvoieLine::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoiceLines;

    /**
     * @var Collection<int, InvoiceReminder>
     */
    #[ORM\OneToMany(targetEntity: InvoiceReminder::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoiceReminders;

    /**
     * @var Collection<int, ElectronicInvoiceProviderSetting>
     */
    #[ORM\OneToMany(targetEntity: ElectronicInvoiceProviderSetting::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $electronicInvoiceProviderSettings;

    /**
     * @var Collection<int, ElectronicInvoiceSubmission>
     */
    #[ORM\OneToMany(targetEntity: ElectronicInvoiceSubmission::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    public Collection $electronicInvoiceSubmissions;

    public function __construct()
    {
        $this->apiTokenHistories = new ArrayCollection();
        $this->taxes = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->clients = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->credit = new ArrayCollection();
        $this->userInvitations = new ArrayCollection();
        $this->apiTokens = new ArrayCollection();
        $this->settings = new ArrayCollection();
        $this->quotes = new ArrayCollection();
        $this->quoteLines = new ArrayCollection();
        $this->paymentMethods = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->userNotifications = new ArrayCollection();
        $this->transportSettings = new ArrayCollection();
        $this->invoices = new ArrayCollection();
        $this->creditNotes = new ArrayCollection();
        $this->creditNoteAllocations = new ArrayCollection();
        $this->recurringInvoices = new ArrayCollection();
        $this->invoiceLines = new ArrayCollection();
        $this->memberships = new ArrayCollection();
        $this->invoiceReminders = new ArrayCollection();
        $this->electronicInvoiceProviderSettings = new ArrayCollection();
        $this->electronicInvoiceSubmissions = new ArrayCollection();
        $this->id = new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->memberships->map(static fn (Membership $membership): User => $membership->getUser());
    }

    /**
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addUser(User $user, CompanyRole $role = CompanyRole::Admin): self
    {
        $user->addCompany($this, $role);

        return $this;
    }

    public function removeUser(User $user): self
    {
        $user->removeCompany($this);

        return $this;
    }

    public function getCustomDomain(): ?string
    {
        return $this->customDomain;
    }

    public function setCustomDomain(?string $customDomain): self
    {
        $this->customDomain = self::normalizeCustomDomain($customDomain);

        return $this;
    }

    public static function normalizeCustomDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $value = $host;
            }
        }

        // strip path / query / fragment if any leaked in
        $value = preg_replace('~[/?#].*$~', '', $value) ?? $value;
        $value = preg_replace('~:\d+$~', '', $value) ?? $value;
        $value = rtrim($value, '.');
        $value = strtolower($value);

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        return $value === '' ? null : $value;
    }
}
