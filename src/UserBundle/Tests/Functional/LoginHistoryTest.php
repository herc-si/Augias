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

namespace Augias\UserBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\LoginOutcome;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use function array_map;

/**
 * The account journal, end to end.
 *
 * Two things have to hold, and only the first is about the feature: signing in
 * really does write a row (rather than the subscriber being wired to an event
 * that never fires), and one company's journal is not another's.
 */
#[Group('functional')]
final class LoginHistoryTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testSigningInIsRecordedAndShownBackToTheAccountHolder(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = $this->accountWithPassword('alpha@sign-ins.test', $company);

        $this->browser()
            ->visit('/login')
            ->fillField('_username', 'alpha@sign-ins.test')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->visit('/profile/sign-ins')
            ->assertSuccessful()
            ->assertSee('Signed in');

        self::assertSame(
            [LoginOutcome::Success],
            $this->outcomesFor($user),
            'Signing in through the form must leave exactly one success in the journal.',
        );
    }

    /**
     * The half that matters: a journal that only showed successes could not
     * answer "is someone trying my password".
     */
    public function testAWrongPasswordIsRecordedToo(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = $this->accountWithPassword('alpha@sign-ins.test', $company);

        $this->browser()
            ->visit('/login')
            ->fillField('_username', 'alpha@sign-ins.test')
            ->fillField('_password', 'not-the-password')
            ->click('Sign in');

        self::assertSame([LoginOutcome::Failure], $this->outcomesFor($user));
    }

    public function testACompanyOnlySeesSignInsOfItsOwnPeople(): void
    {
        $alpha = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $beta = CompanyFactory::createOne(['name' => 'Beta Inc']);

        $alphaUser = $this->accountWithPassword('alpha@sign-ins.test', $alpha);
        $betaUser = UserFactory::createOne([
            'email' => 'beta@sign-ins.test',
            'companies' => [$beta],
        ]);

        $this->record($alphaUser, 'alpha@sign-ins.test');
        $this->record($betaUser, 'beta@sign-ins.test');
        // Nobody's account, so nobody's to read: an attempt on an address that
        // matches nothing belongs to the deployment, not to a tenant.
        $this->record(null, 'nobody@sign-ins.test', LoginOutcome::Failure);

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($alphaUser)
            ->visit('/users/sign-ins')
            ->assertSuccessful()
            ->assertSee('alpha@sign-ins.test')
            ->assertNotSee('beta@sign-ins.test')
            ->assertNotSee('nobody@sign-ins.test');
    }

    private function accountWithPassword(string $email, Company $company): User
    {
        $user = UserFactory::createOne(['email' => $email, 'companies' => [$company]]);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user->setPassword($hasher->hashPassword($user, 'password'));
        $this->em->flush();

        return $user;
    }

    private function record(?User $user, string $identifier, LoginOutcome $outcome = LoginOutcome::Success): void
    {
        $this->em->persist(new LoginRecord($user, $identifier, $outcome, new DateTimeImmutable()));
        $this->em->flush();
    }

    /**
     * @return list<LoginOutcome>
     */
    private function outcomesFor(User $user): array
    {
        $this->em->clear();

        $records = $this->em->getRepository(LoginRecord::class)->findBy(['user' => $user->getId()]);

        return array_map(static fn (LoginRecord $record): LoginOutcome => $record->getOutcome(), $records);
    }
}
