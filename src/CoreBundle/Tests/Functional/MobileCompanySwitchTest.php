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

namespace Augias\CoreBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function sprintf;

/**
 * The top bar, where companies are switched, is hidden on a phone: the
 * user menu of the sidebar, shown there, offers the other companies.
 */
#[Group('functional')]
final class MobileCompanySwitchTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testThePhoneMenuOffersTheOtherCompanies(): void
    {
        $other = CompanyFactory::createOne(['name' => 'Tricatel']);
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $user = $this->em->find(User::class, $user->getId());
        $other = $this->em->find(Company::class, $other->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Company::class, $other);
        $user->addCompany($other, CompanyRole::Owner);
        $this->em->flush();

        $this->browser()
            ->actingAs($user)
            ->visit('/select-company/' . $this->company->getId())
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertSeeElement(sprintf('aside .navbar-nav.d-lg-none a[href="/select-company/%s"]', $other->getId()));
    }
}
