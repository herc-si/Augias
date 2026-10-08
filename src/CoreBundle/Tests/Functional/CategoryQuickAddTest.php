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

use Augias\CoreBundle\Action\Category\QuickAdd;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use function json_decode;

/**
 * The "+" beside the catalogue's category dropdown, driven with the token the
 * page itself hands out.
 */
#[CoversClass(QuickAdd::class)]
final class CategoryQuickAddTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    public function testANewNameIsCreatedForTheCatalogue(): void
    {
        $this->client->request('POST', '/categories/quick-add', ['name' => '  Bâtiment ', 'usage' => 'catalog', '_token' => $this->token()]);

        self::assertResponseStatusCodeSame(201);

        $category = $this->find('Bâtiment');
        self::assertInstanceOf(Category::class, $category);
        self::assertTrue($category->isUsedForCatalog());
        self::assertFalse($category->isUsedForPurchases());

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['id' => (string) $category->getId(), 'name' => 'Bâtiment'], $answer);
    }

    /**
     * The list is unique by name, so a purchase category of the same name is
     * offered to the catalogue as well rather than refused.
     */
    public function testAnExistingNameIsReusedAndGainsTheUsage(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = new Category()->setName('Matériel')->setUsedForPurchases(true);
        $company = $entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $existing->setCompany($company);
        $entityManager->persist($existing);
        $entityManager->flush();

        $this->client->request('POST', '/categories/quick-add', ['name' => 'Matériel', 'usage' => 'catalog', '_token' => $this->token()]);

        self::assertResponseStatusCodeSame(200);

        $entityManager->clear();
        $category = $this->find('Matériel');
        self::assertInstanceOf(Category::class, $category);
        self::assertSame((string) $existing->getId(), (string) $category->getId());
        self::assertTrue($category->isUsedForCatalog());
        self::assertTrue($category->isUsedForPurchases());
    }

    public function testABlankNameIsRefused(): void
    {
        $this->client->request('POST', '/categories/quick-add', ['name' => '   ', 'usage' => 'catalog', '_token' => $this->token()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::getContainer()->get(CategoryRepository::class)->findAll());
    }

    public function testARequestWithoutTheTokenIsRefused(): void
    {
        $this->client->request('POST', '/categories/quick-add', ['name' => 'Bâtiment', 'usage' => 'catalog', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->find('Bâtiment'));
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/catalog/add');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-category-quick-add-token-value]')->attr('data-category-quick-add-token-value');
    }

    private function find(string $name): ?Category
    {
        return self::getContainer()->get(CategoryRepository::class)->findOneBy(['name' => $name]);
    }
}
