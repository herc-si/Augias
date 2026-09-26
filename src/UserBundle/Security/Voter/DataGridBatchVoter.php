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

namespace Augias\UserBundle\Security\Voter;

use Augias\TaxBundle\Entity\Tax;
use Augias\UserBundle\Entity\ApiToken;
use Augias\UserBundle\Entity\ApiTokenHistory;
use Augias\UserBundle\Entity\UserInvitation;
use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Security\CompanyAccess;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use function is_string;

/**
 * Whether the member may run a list's batch actions — delete, archive,
 * revoke — on what that list holds.
 *
 * A list is read by anyone; its batch actions change things, and what they
 * change decides the right: a person's own API tokens are theirs, tax rates
 * are settings, invitations are members, everything else is billing.
 *
 * @extends Voter<string, class-string>
 */
final class DataGridBatchVoter extends Voter
{
    public const string ATTRIBUTE = 'datagrid.batch';

    public function __construct(
        private readonly CompanyAccess $access,
    ) {
    }

    #[Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && is_string($subject);
    }

    #[Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return match ($subject) {
            ApiToken::class, ApiTokenHistory::class => true,
            Tax::class => $this->access->can(CompanyPermission::Settings),
            UserInvitation::class => $this->access->can(CompanyPermission::ManageMembers),
            default => $this->access->can(CompanyPermission::BillingWrite),
        };
    }
}
