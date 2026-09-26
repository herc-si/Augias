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

namespace Augias\UserBundle\DataFixtures\ORM;

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

/**
 * @codeCoverageIgnore
 */
class LoadData extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $company = $manager->getRepository(Company::class)->findOneBy([]);

        if (! $company instanceof Company) {
            throw new RuntimeException('No company found');
        }

        $user1 = new User()
            ->setEmail('test1@test.com')
            ->setPassword('test1')
            ->addCompany($company, CompanyRole::Owner)
        ;

        $user2 = new User()
            ->setEmail('test2@test.com')
            ->setPassword('test2')
            ->setEnabled(true)
            ->addCompany($company)
        ;

        $manager->persist($user1);
        $manager->persist($user2);
        $manager->flush();

        $this->setReference('user1', $user1);
        $this->setReference('user2', $user2);
    }
}
