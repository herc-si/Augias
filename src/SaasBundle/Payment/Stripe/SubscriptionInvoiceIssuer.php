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

namespace Augias\SaasBundle\Payment\Stripe;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\PaymentBundle\Event\PaymentCompleteEvent;
use Augias\PaymentBundle\Event\PaymentEvents;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function strtoupper;

/**
 * Issues HERC SI's invoice for a subscription payment Stripe collected.
 *
 * HERC SI sells the subscription: the invoice is Augias's own, in HERC SI's
 * company on the hosted instance (AUGIAS_SAAS_BILLING_COMPANY), numbered in
 * its sequence, marked paid, emailed to the subscriber, and sent as an
 * electronic invoice when the subscriber has a SIREN. Stripe's invoice is
 * only its record of the charge and is never sent.
 *
 * Stripe may deliver `invoice.paid` more than once: the Stripe invoice id is
 * kept as the payment's reference, and a payment already recorded under it
 * means the invoice exists.
 *
 * The subscriber is found among HERC SI's clients by SIREN, then by email,
 * so that one already invoiced for something else stays one client.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\SubscriptionInvoiceIssuerTest
 */
final readonly class SubscriptionInvoiceIssuer
{
    public function __construct(
        private StripeApi $api,
        private SubscriptionRepositoryInterface $subscriptions,
        private PlanRepositoryInterface $plans,
        private CompanySelector $companySelector,
        private EntityManagerInterface $entityManager,
        private TaxIdentifierRepository $taxIdentifiers,
        private SystemConfig $systemConfig,
        private InvoiceManager $invoiceManager,
        private BillingIdGenerator $billingIdGenerator,
        private TotalCalculator $totalCalculator,
        #[Autowire(service: 'state_machine.invoice')]
        private WorkflowInterface $invoiceStateMachine,
        private EventDispatcherInterface $eventDispatcher,
        private MailerInterface $mailer,
        private ElectronicInvoiceManagerInterface $electronicInvoiceManager,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'AUGIAS_SAAS_BILLING_COMPANY')]
        private string $billingCompany,
    ) {
    }

    public function issue(string $stripeInvoiceId): ?Invoice
    {
        if ($this->billingCompany === '') {
            $this->logger->warning('AUGIAS_SAAS_BILLING_COMPANY is not set: no invoice issued for a Stripe payment.', ['stripe_invoice' => $stripeInvoiceId]);

            return null;
        }

        $remote = $this->api->get('invoices/' . $stripeInvoiceId);
        $amount = $remote['amount_paid'] ?? 0;

        // A trial's first invoice, or one a credit covered: nothing was paid.
        if (! is_int($amount) || $amount <= 0 || ($remote['status'] ?? null) !== 'paid') {
            return null;
        }

        $subscription = $this->findSubscription($remote);

        if (! $subscription instanceof Subscription) {
            $this->logger->warning('Stripe payment for a subscription unknown here: no invoice issued.', ['stripe_invoice' => $stripeInvoiceId]);

            return null;
        }

        $subscriber = $subscription->getSubscriber();
        if (! $subscriber instanceof Company) {
            return null;
        }

        // Read while no company is selected: the subscriber's identifiers
        // are out of reach once HERC SI's company filters the queries.
        $this->companySelector->reset();
        $identifiers = $this->subscriberIdentifiers($subscriber);

        $this->companySelector->switchCompany(Ulid::fromString($this->billingCompany));

        try {
            $existing = $this->entityManager->getRepository(Payment::class)->findOneBy(['reference' => $stripeInvoiceId]);
            if ($existing instanceof Payment) {
                return $existing->getInvoice();
            }

            $client = $this->client($subscriber, $remote, $identifiers);
            $paidAt = $this->paidAt($remote);

            $invoice = new Invoice();
            $invoice->setClient($client);
            $invoice->setInvoiceDate($paidAt);
            $invoice->setDue($paidAt);
            $invoice->addLine(new Line()
                ->setDescription($this->description($remote, $subscription))
                ->setQty(1)
                ->setPrice($amount));
            foreach ($client->getContacts() as $contact) {
                $invoice->addUser($contact);
            }
            $invoice->setInvoiceId($this->billingIdGenerator->generate($invoice, ['field' => 'invoiceId']));
            $this->totalCalculator->calculateTotals($invoice);

            $this->invoiceManager->create($invoice);
            $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);

            $this->recordPayment($invoice, $client, $amount, strtoupper((string) ($remote['currency'] ?? 'eur')), $stripeInvoiceId, $paidAt);

            $this->deliver($invoice);

            return $invoice;
        } finally {
            $this->companySelector->reset();
        }
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function findSubscription(array $remote): ?Subscription
    {
        $details = $remote['subscription_details'] ?? null;
        $reference = is_array($details) && is_array($details['metadata'] ?? null) ? ($details['metadata'][StripeIntegration::METADATA_KEY] ?? null) : null;

        if (is_string($reference)) {
            try {
                $subscription = $this->subscriptions->findOneBy(['id' => Ulid::fromBase58($reference)]);
                if ($subscription instanceof Subscription) {
                    return $subscription;
                }
            } catch (InvalidArgumentException) {
                // Not one of ours: fall back on the Stripe subscription id.
            }
        }

        $subscription = is_string($remote['subscription'] ?? null) ? $this->subscriptions->findOneBy(['subscriptionId' => $remote['subscription']]) : null;

        return $subscription instanceof Subscription ? $subscription : null;
    }

    /**
     * @return array<string, string> the subscriber's SIREN, SIRET, VAT number, by label
     */
    private function subscriberIdentifiers(Company $subscriber): array
    {
        $identifiers = [];
        foreach ($this->taxIdentifiers->findCompanyIdentifiers($subscriber->getId()) as $identifier) {
            $label = (string) $identifier->getLabel();
            if (in_array($label, TaxIdentifierType::PROMINENT_LABELS, true) && ! isset($identifiers[$label]) && (string) $identifier->getValue() !== '') {
                $identifiers[$label] = (string) $identifier->getValue();
            }
        }

        return $identifiers;
    }

    /**
     * @param array<string, mixed>  $remote
     * @param array<string, string> $identifiers
     */
    private function client(Company $subscriber, array $remote, array $identifiers): Client
    {
        $email = is_string($remote['customer_email'] ?? null) ? $remote['customer_email'] : null;

        $client = $this->findClient($identifiers[TaxIdentifierType::SIREN] ?? null, $email);
        if ($client instanceof Client) {
            return $client;
        }

        $client = new Client();
        $client->setName($subscriber->getName());
        $client->setCurrencyCode('EUR');
        $client->setIsCompany(isset($identifiers[TaxIdentifierType::SIREN]) || isset($identifiers[TaxIdentifierType::SIRET]));
        $client->setSiren($identifiers[TaxIdentifierType::SIREN] ?? null);
        $client->setSiret($identifiers[TaxIdentifierType::SIRET] ?? null);
        $client->setVatNumber($identifiers[TaxIdentifierType::VAT_NUMBER] ?? null);

        $address = $remote['customer_address'] ?? null;
        if (is_array($address)) {
            $client->addAddress(new Address()
                ->setStreet1(self::string($address['line1'] ?? null))
                ->setStreet2(self::string($address['line2'] ?? null))
                ->setZip(self::string($address['postal_code'] ?? null))
                ->setCity(self::string($address['city'] ?? null))
                ->setCountry(self::string($address['country'] ?? null)));

            if (($address['country'] ?? 'FR') !== 'FR') {
                $this->logger->warning('Subscription paid from outside France, where the service is not sold yet.', ['country' => $address['country']]);
            }
        }

        if ($email !== null) {
            $client->addContact(new Contact()
                ->setFirstName(self::string($remote['customer_name'] ?? null) ?? $subscriber->getName())
                ->setEmail($email));
        }

        $this->entityManager->persist($client);

        return $client;
    }

    private function findClient(?string $siren, ?string $email): ?Client
    {
        if ($siren !== null) {
            $identifier = $this->taxIdentifiers->findOneBy(['label' => TaxIdentifierType::SIREN, 'value' => $siren]);
            if ($identifier instanceof TaxIdentifier && $identifier->getClient() instanceof Client) {
                return $identifier->getClient();
            }
        }

        if ($email !== null) {
            $contact = $this->entityManager->getRepository(Contact::class)->findOneBy(['email' => $email]);
            if ($contact instanceof Contact && $contact->getClient() instanceof Client) {
                return $contact->getClient();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function description(array $remote, Subscription $subscription): string
    {
        $line = $remote['lines']['data'][0] ?? [];
        $priceId = $line['price']['id'] ?? null;
        $plan = is_string($priceId) ? $this->plans->find($priceId) : null;
        $planName = ($plan instanceof Plan ? $plan : $subscription->getPlan())->getName();

        $start = $line['period']['start'] ?? null;
        $end = $line['period']['end'] ?? null;
        $locale = (string) ($this->systemConfig->get('system/company/locale') ?? 'fr');

        $key = ($remote['billing_reason'] ?? null) === 'subscription_update' ? 'saas.billing.invoice_line.plan_change' : 'saas.billing.invoice_line.subscription';

        return $this->translator->trans($key, [
            '%plan%' => $planName,
            '%from%' => is_int($start) ? StripeIntegration::fromTimestamp($start)->format('d/m/Y') : '',
            '%to%' => is_int($end) ? StripeIntegration::fromTimestamp($end)->format('d/m/Y') : '',
        ], 'messages', $locale);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function paidAt(array $remote): DateTimeImmutable
    {
        $paidAt = $remote['status_transitions']['paid_at'] ?? null;

        return is_int($paidAt) ? StripeIntegration::fromTimestamp($paidAt) : new DateTimeImmutable();
    }

    private function recordPayment(Invoice $invoice, Client $client, int $amount, string $currency, string $stripeInvoiceId, DateTimeImmutable $paidAt): void
    {
        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setClient($client);
        $payment->setMethod($this->paymentMethod());
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode($currency);
        $payment->setDescription('');
        $payment->setNumber($invoice->getId()?->toString());
        $payment->setReference($stripeInvoiceId);
        $payment->setNotes('Stripe');
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted($paidAt);
        $payment->setCompany($invoice->getCompany());
        $invoice->addPayment($payment);

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        // Balance, paid status and the notification, as for a payment typed in.
        $this->eventDispatcher->dispatch(new PaymentCompleteEvent($payment), PaymentEvents::PAYMENT_COMPLETE);
    }

    /**
     * An offline method named "stripe" when HERC SI created one, so that
     * these payments stand apart; otherwise any offline method.
     */
    private function paymentMethod(): PaymentMethod
    {
        $repository = $this->entityManager->getRepository(PaymentMethod::class);
        $method = $repository->findOneBy(['factoryName' => PaymentMethod::FACTORY_OFFLINE, 'gatewayName' => 'stripe'])
            ?? $repository->findOneBy(['factoryName' => PaymentMethod::FACTORY_OFFLINE]);

        if (! $method instanceof PaymentMethod) {
            throw new LogicException('The billing company has no offline payment method to record Stripe payments with.');
        }

        return $method;
    }

    /**
     * The email and the electronic invoice are two deliveries of the same
     * invoice: neither failing undoes it, nor keeps the other from going.
     */
    private function deliver(Invoice $invoice): void
    {
        if (! $invoice->getUsers()->isEmpty()) {
            try {
                $this->mailer->send(new InvoiceEmail($invoice));
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Could not email a subscription invoice.', ['exception' => $e, 'invoice' => $invoice->getInvoiceId()]);
            }
        }

        if (! $this->electronicInvoiceManager->isEligible($invoice)) {
            return;
        }

        try {
            $this->electronicInvoiceManager->send($invoice);
        } catch (LogicException $e) {
            $this->logger->error('Could not send a subscription invoice electronically.', ['exception' => $e, 'invoice' => $invoice->getInvoiceId()]);
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
