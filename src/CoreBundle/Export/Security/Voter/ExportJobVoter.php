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

namespace Augias\CoreBundle\Export\Security\Voter;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\ExportJob;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Security\CompanyAccess;
use SensitiveParameter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Ulid;

/**
 * @extends Voter<string, ExportJob>
 * @see \Augias\CoreBundle\Tests\Export\Security\Voter\ExportJobVoterTest
 */
final class ExportJobVoter extends Voter
{
    public const string DOWNLOAD = 'EXPORT_DOWNLOAD';

    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly ?CompanyAccess $access = null,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::DOWNLOAD && $subject instanceof ExportJob;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, #[SensitiveParameter] TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (! $user instanceof User) {
            return false;
        }

        assert($subject instanceof ExportJob);

        $activeCompanyId = $this->companySelector->getCompany();
        if (! $activeCompanyId instanceof Ulid || ! $subject->getCompany()->getId()->equals($activeCompanyId)) {
            return false;
        }

        if ($subject->getRequestedBy()->equals($user->getId())) {
            return true;
        }

        // A closing company's archive is for all who run it, whoever it was
        // filed under. The role is read as it stands: a closing company
        // otherwise grants its people nothing beyond reading.
        return $subject->isForClosure() && true === $this->access?->role()?->can(CompanyPermission::ManageMembers);
    }
}
