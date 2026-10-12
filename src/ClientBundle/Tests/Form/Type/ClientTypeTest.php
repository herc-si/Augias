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

namespace Augias\ClientBundle\Tests\Form\Type;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Form\Type\ClientType;
use Augias\ClientBundle\Form\Type\ContactType;
use Augias\CoreBundle\Enum\CustomFieldTarget;
use Augias\CoreBundle\Form\Type\CustomFieldValueCollectionType;
use Augias\CoreBundle\Repository\CustomFieldRepository;
use Augias\CoreBundle\Repository\CustomFieldValueRepository;
use Augias\CoreBundle\Service\CustomField\CustomFieldTypeResolver;
use Augias\CoreBundle\Tests\FormTestCase;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as M;
use Money\Currency;
use Override;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\Form\PreloadedExtension;

final class ClientTypeTest extends FormTestCase
{
    /**
     * Mutable list of feature keys that should report as DISABLED — every other key is enabled.
     *
     * @var list<string>
     */
    private array $disabledFeatures = [];

    public function testSubmit(): void
    {
        $this->disabledFeatures = [];

        $company = $this->faker->company();
        $url = $this->faker->url();
        $currencyCode = 'USD';

        $formData = [
            'name' => $company,
            'website' => $url,
            'currencyCode' => $currencyCode,
            'contacts' => [],
            'addresses' => [],
        ];

        $object = new Client();
        $object->setName($company);
        $object->setWebsite($url);
        $object->setCurrencyCode($currencyCode);

        $this->assertFormData(ClientType::class, $formData, $object);
    }

    public function testSubmitFillsNameFromThePrimaryContactWhenLeftEmptyForAnIndividual(): void
    {
        $this->disabledFeatures = ['custom_fields'];

        $formData = [
            'isCompany' => '0',
            'name' => '',
            'currencyCode' => 'USD',
            'contacts' => [
                ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.com'],
            ],
            'addresses' => [],
        ];

        $form = $this->factory->create(ClientType::class, new Client());
        $form->submit($formData);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Jane Doe', $form->getData()->getName());
    }

    /**
     * The type is said, not guessed: an individual saved again with a name, or
     * with a typo in it, stays an individual.
     */
    public function testAnIndividualStaysOneWhateverTheirName(): void
    {
        $this->disabledFeatures = ['custom_fields'];

        $client = new Client();
        $client->setName('Jane Doe');
        $client->setIsCompany(false);

        $form = $this->factory->create(ClientType::class, $client);
        $form->submit([
            'isCompany' => '0',
            'name' => 'Jane Do',
            'currencyCode' => 'USD',
            'contacts' => [
                ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.com'],
            ],
            'addresses' => [],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($client->isCompany());
        self::assertSame('Jane Do', $client->getName());
    }

    public function testChoosingBusinessMakesItACompany(): void
    {
        $this->disabledFeatures = ['custom_fields'];

        $client = new Client();
        $client->setName('Jane Doe');
        $client->setIsCompany(false);

        $form = $this->factory->create(ClientType::class, $client);
        $form->submit([
            'isCompany' => '1',
            'name' => 'Doe Consulting',
            'currencyCode' => 'USD',
            'contacts' => [
                ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.com'],
            ],
            'addresses' => [],
        ]);

        self::assertTrue($client->isCompany());
    }

    /**
     * A business left without a name is not given its contact's: that is the
     * individual's shortcut, and a business has to be named.
     */
    public function testABusinessIsNotNamedAfterItsContact(): void
    {
        $this->disabledFeatures = ['custom_fields'];

        $form = $this->factory->create(ClientType::class, new Client());
        $form->submit([
            'isCompany' => '1',
            'name' => '',
            'currencyCode' => 'USD',
            'contacts' => [
                ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.com'],
            ],
            'addresses' => [],
        ]);

        self::assertNull($form->getData()->getName());
    }

    public function testSubmitWithMultiCurrencyGatedOverridesEntityCurrency(): void
    {
        $this->disabledFeatures = ['multi_currency'];

        $object = new Client();
        $object->setName($this->faker->company());
        $object->setCurrencyCode('EUR');

        $form = $this->factory->create(ClientType::class, $object);

        // The currencyCode field is disabled when gated, so the submitted value is ignored;
        // the SUBMIT listener overrides the entity's currencyCode to the company default.
        $form->submit([
            'name' => $object->getName(),
            'currencyCode' => 'EUR',
            'contacts' => [],
            'addresses' => [],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('USD', $object->getCurrencyCode());
        self::assertTrue($form->get('currencyCode')->isDisabled());
        self::assertSame('multi_currency', $form->get('currencyCode')->getConfig()->getOption('feature_gated'));
    }

    /**
     * @return PreloadedExtension[]
     */
    #[Override]
    protected function getExtensions(): array
    {
        $featureGate = M::mock(FeatureGate::class);
        $featureGate->shouldReceive('isEnabled')
            ->andReturnUsing(fn (string $key): bool => ! in_array($key, $this->disabledFeatures, true));

        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldReceive('getCurrency')->andReturn(new Currency('USD'));

        $fieldRepo = M::mock(CustomFieldRepository::class);
        $fieldRepo->shouldReceive('findByTargetOrdered')
            ->with(M::type(CustomFieldTarget::class))
            ->andReturn([]);

        $valueRepo = M::mock(CustomFieldValueRepository::class);
        $em = M::mock(EntityManagerInterface::class);
        $em->shouldReceive('contains')->zeroOrMoreTimes()->andReturn(false);

        return [
            // register the type instances with the PreloadedExtension
            new PreloadedExtension([
                new ClientType($featureGate, $systemConfig),
                new ContactType($featureGate),
                new CustomFieldValueCollectionType($fieldRepo, $valueRepo, new CustomFieldTypeResolver(), $em),
                new CurrencyType('en'),
            ], []),
        ];
    }
}
