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

namespace Augias\SaasBundle\Support;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Entity\SupportSettings;
use Augias\CoreBundle\Enum\SupportRequestStatus;
use Augias\SaasBundle\Feature\Feature;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\Uid\Ulid;
use function in_array;

/**
 * Requests for help, from the company asking to whoever answers.
 *
 * Every change to a request goes through here — the company's screens and the
 * operator's tooling alike — so that the rules (the door opens for a set time,
 * only the one who took a request may come in, nobody closes one without
 * saying what was done) are kept in one place, and so is the mail that tells
 * the company each time.
 */
final readonly class SupportDesk
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private SupportMailer $mailer,
        private CompanySelector $companySelector,
        private FeatureGate $featureGate,
    ) {
    }

    /**
     * The deployment's terms; a fresh, switched-off set when none were saved.
     */
    public function settings(): SupportSettings
    {
        return $this->entityManager->getRepository(SupportSettings::class)->findOneBy([], ['id' => 'ASC']) ?? new SupportSettings();
    }

    public function isEnabled(): bool
    {
        return $this->settings()->isEnabled();
    }

    public function saveSettings(SupportSettings $settings): void
    {
        $settings->setUpdatedAt($this->clock->now());
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    public function open(Company $company, string $member, string $message, int $hours): SupportRequest
    {
        $settings = $this->settings();

        if (! $settings->isEnabled()) {
            throw new InvalidArgumentException('Requests for help are not taken on this deployment.');
        }

        if (! $this->featureGate->isEnabled(Feature::SupportAccess->value, $company)) {
            throw new InvalidArgumentException('This company\'s plan does not include help from inside it.');
        }

        if (! in_array($hours, $settings->getDurations(), true)) {
            throw new InvalidArgumentException('That span is not offered.');
        }

        $now = $this->clock->now();
        $request = new SupportRequest($company, $member, trim($message), $hours, $now, $now->modify('+' . $hours . ' hours'));

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->mailer->requested($request, $settings);

        return $request;
    }

    public function revoke(SupportRequest $request, string $member): void
    {
        $request->revoke($member, $this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * Taken by someone running the service, who may now come in. The company
     * is told who, at the moment it happens.
     */
    public function accept(SupportRequest $request, string $operator): void
    {
        $wasPending = SupportRequestStatus::Pending === $request->getStatus();
        $request->accept($operator, $this->clock->now());
        $this->entityManager->flush();

        if ($wasPending) {
            $this->mailer->accepted($request, $this->settings());
        }
    }

    public function resolve(SupportRequest $request, string $operator, string $note): void
    {
        $request->resolve($operator, $note, $this->clock->now());
        $this->entityManager->flush();

        $this->mailer->resolved($request, $this->settings());
    }

    /**
     * Found whatever company is open — the operator's tooling has none, and a
     * visitor arriving is not yet in the one the request is about.
     */
    public function find(Ulid $id): ?SupportRequest
    {
        return $this->unfiltered(fn (): ?SupportRequest => $this->entityManager->find(SupportRequest::class, $id));
    }

    /**
     * The requests this person has taken and may still use, across companies.
     *
     * @return list<SupportRequest>
     */
    public function admitting(string $operator): array
    {
        $now = $this->clock->now();

        /** @var list<SupportRequest> $requests */
        $requests = $this->unfiltered(fn (): array => $this->entityManager->getRepository(SupportRequest::class)->findBy([
            'status' => SupportRequestStatus::Accepted,
            'operator' => $operator,
        ]));

        return array_values(array_filter($requests, static fn (SupportRequest $request): bool => $request->admits($operator, $now)));
    }

    /**
     * Every request, newest first, for whoever answers them.
     *
     * @return list<SupportRequest>
     */
    public function all(int $limit = 200): array
    {
        /** @var list<SupportRequest> $requests */
        $requests = $this->unfiltered(fn (): array => $this->entityManager->getRepository(SupportRequest::class)->findBy([], ['requestedAt' => 'DESC'], $limit));

        return $requests;
    }

    /**
     * The open company's requests, newest first.
     *
     * @return list<SupportRequest>
     */
    public function forCompany(Company $company): array
    {
        /** @var list<SupportRequest> $requests */
        $requests = $this->entityManager->getRepository(SupportRequest::class)->findBy(['company' => $company], ['requestedAt' => 'DESC']);

        return $requests;
    }

    /**
     * The company's request whose door is open now, if any.
     */
    public function openFor(Company $company): ?SupportRequest
    {
        $now = $this->clock->now();

        foreach ($this->forCompany($company) as $request) {
            if ($request->isOpen($now)) {
                return $request;
            }
        }

        return null;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function unfiltered(callable $read): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if (! $wasEnabled) {
            return $read();
        }

        $selected = $this->companySelector->getCompany();
        $filters->disable('company');

        try {
            return $read();
        } finally {
            // Through the selector, not $filters->enable(): Doctrine re-enables
            // a filter without its parameters, and the company filter without
            // its company filters nothing for the rest of the request. This is
            // asked on every page of a visit, after the company is opened, so
            // the visitor saw every tenant's figures (test instance, 01/10/2026:
            // two quotes counted in a company that had none).
            if ($selected instanceof Ulid) {
                $this->companySelector->switchCompany($selected);
            } else {
                $filters->enable('company');
            }
        }
    }
}
