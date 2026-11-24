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

namespace Augias\McpBundle\Assistant;

use Augias\McpBundle\Entity\AssistantEvent;
use Augias\McpBundle\Enum\AssistantEventKind;
use Augias\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes down what the assistant did, for the company open and the person
 * signed in: who, when, which tool. Outside a company (a test, a command),
 * nothing is written.
 */
final readonly class AssistantJournal
{
    public function __construct(
        private CurrentCompany $currentCompany,
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function record(AssistantEventKind $kind, ?string $tool = null, bool $failed = false): void
    {
        $company = $this->currentCompany->get();

        if ($company === null) {
            return;
        }

        $user = $this->security->getUser();

        $this->entityManager->persist(new AssistantEvent($company, $user instanceof User ? $user : null, $kind, $tool, $failed, $this->clock->now()));
        $this->entityManager->flush();
    }
}
