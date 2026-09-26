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

namespace Augias\UserBundle\Entity;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Export\Attribute\ExportIgnore;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SolidWorx\Platform\PlatformBundle\Security\TwoFactor\Traits\UserTwoFactor;
use SolidWorx\Platform\SaasBundle\Trial\TrialUserInterface;

#[ORM\Table(name: User::TABLE_NAME)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ExportIgnore]
class User extends \SolidWorx\Platform\PlatformBundle\Model\User implements TrialUserInterface
{
    final public const string TABLE_NAME = 'users';

    use TimeStampable;
    use UserTwoFactor;

    /**
     * @var Collection<int, ApiToken>
     */
    #[ORM\OneToMany(targetEntity: ApiToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    private Collection $apiTokens;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    /**
     * @deprecated This should not be used anymore. Remove once all usages are gone.
     */
    public ?string $plainPassword = null;

    public function __construct()
    {
        parent::__construct();
        $this->apiTokens = new ArrayCollection();
        $this->memberships = new ArrayCollection();
    }

    /**
     * Ensure the plain-text password is never serialized (e.g. into the session).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset($data['plainPassword']);

        return $data;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function getApiTokens(): Collection
    {
        return $this->apiTokens;
    }

    /**
     * @param Collection<int, ApiToken> $apiTokens
     */
    public function setApiTokens(Collection $apiTokens): static
    {
        $this->apiTokens = $apiTokens;

        return $this;
    }

    /**
     * The companies this user belongs to, whatever the role.
     *
     * @return Collection<int, Company>
     */
    public function getCompanies(): Collection
    {
        return $this->memberships->map(static fn (Membership $membership): Company => $membership->getCompany());
    }

    /**
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function getMembership(Company $company): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getCompany() === $company || $membership->getCompany()->getId()->equals($company->getId())) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Joins the company in the given role, or changes nothing if already a
     * member. Administrator by default, which is what every member was before
     * roles existed; the places where someone joins — creating a company,
     * accepting an invitation — say which role.
     */
    public function addCompany(Company $company, CompanyRole $role = CompanyRole::Admin): static
    {
        if (! $this->getMembership($company) instanceof Membership) {
            $membership = new Membership($this, $company, $role);
            $this->memberships->add($membership);
            $company->getMemberships()->add($membership);
        }

        return $this;
    }

    public function removeCompany(Company $company): static
    {
        $membership = $this->getMembership($company);

        if ($membership instanceof Membership) {
            $this->memberships->removeElement($membership);
            $company->getMemberships()->removeElement($membership);
        }

        return $this;
    }
}
