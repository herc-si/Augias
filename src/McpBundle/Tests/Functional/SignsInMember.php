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

namespace Augias\McpBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Signs in a member of the test company, as the MCP authenticator does with
 * the token's owner: a tool acts for someone, within that person's role.
 */
trait SignsInMember
{
    private ?User $member = null;

    private function signInMember(CompanyRole $role = CompanyRole::Admin): User
    {
        if (! $this->member instanceof User) {
            $em = self::getContainer()->get('doctrine')->getManager();
            $member = UserFactory::createOne(['companies' => []]);
            self::assertInstanceOf(User::class, $member);
            $company = $em->find(Company::class, $this->company->getId());
            self::assertInstanceOf(Company::class, $company);
            $member = $em->find(User::class, $member->getId());
            self::assertInstanceOf(User::class, $member);
            $member->addCompany($company, $role);
            $em->flush();
            $this->member = $member;
        }

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->member, 'mcp', $this->member->getRoles()));

        return $this->member;
    }
}
