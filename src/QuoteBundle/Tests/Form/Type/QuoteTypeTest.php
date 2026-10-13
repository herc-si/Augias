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

namespace Augias\QuoteBundle\Tests\Form\Type;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Form\Type\CustomFieldValueCollectionType;
use Augias\CoreBundle\Form\Type\DiscountType;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\CoreBundle\Repository\CustomFieldRepository;
use Augias\CoreBundle\Repository\CustomFieldValueRepository;
use Augias\CoreBundle\Service\CustomField\CustomFieldTypeResolver;
use Augias\CoreBundle\Tests\FormTestCase;
use Augias\QuoteBundle\DTO\QuoteFormDTO;
use Augias\QuoteBundle\Enum\QuoteClientMode;
use Augias\QuoteBundle\Form\Type\ItemType;
use Augias\QuoteBundle\Form\Type\QuoteType;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Service\TaxAvailability;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as M;
use Money\Currency;
use Override;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\UX\Autocomplete\Checksum\ChecksumCalculator;
use Symfony\UX\Autocomplete\Form\AutocompleteChoiceTypeExtension;

final class QuoteTypeTest extends FormTestCase
{
    public function testSubmit(): void
    {
        $notes = $this->faker->text();
        $terms = $this->faker->text();
        $discountValue = $this->faker->numberBetween(0, 100);
        $client = ClientFactory::createOne();

        $formData = [
            'clientMode' => 'existing',
            'client' => $client->getId()
                ->toString(),
            'discount' => [
                'value' => $discountValue,
                'type' => Discount::TYPE_PERCENTAGE,
            ],
            'lines' => [],
            'quoteId' => '10',
            'notes' => $notes,
            'terms' => $terms,
            'total' => '0',
            'baseTotal' => '0',
            'tax' => '0',
            'users' => [],
        ];

        $dto = new QuoteFormDTO();
        $dto->clientMode = QuoteClientMode::Existing;
        $dto->client = $client;
        $dto->quoteId = '10';
        $dto->terms = $terms;
        $dto->notes = $notes;

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        // A percentage is stored as typed: the form scales the money side only.
        $discount->setValue(BigDecimal::of($discountValue));

        $dto->discount = $discount;
        $dto->total = '0';
        $dto->baseTotal = '0';
        $dto->tax = '0';

        $this->assertFormData($this->factory->create(QuoteType::class, new QuoteFormDTO()), $formData, $dto);
    }

    public function testSubmitWithNewClient(): void
    {
        $notes = $this->faker->text();
        $terms = $this->faker->text();
        $discountValue = $this->faker->numberBetween(0, 100);

        $formData = [
            'clientMode' => 'new',
            'newClientIsCompany' => '1',
            'newClientName' => 'New Client',
            'newClientSiret' => '12345678900012',
            'newContactFirstName' => 'John',
            'newContactLastName' => 'Doe',
            'newContactEmail' => 'john@example.com',
            'discount' => [
                'value' => $discountValue,
                'type' => Discount::TYPE_PERCENTAGE,
            ],
            'lines' => [],
            'quoteId' => '10',
            'notes' => $notes,
            'terms' => $terms,
            'total' => '0',
            'baseTotal' => '0',
            'tax' => '0',
        ];

        $dto = new QuoteFormDTO();
        $dto->clientMode = QuoteClientMode::NewClient;
        $dto->newClientName = 'New Client';
        $dto->newClientSiret = '12345678900012';
        $dto->newContactFirstName = 'John';
        $dto->newContactLastName = 'Doe';
        $dto->newContactEmail = 'john@example.com';
        $dto->quoteId = '10';
        $dto->terms = $terms;
        $dto->notes = $notes;

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        // A percentage is stored as typed: the form scales the money side only.
        $discount->setValue(BigDecimal::of($discountValue));

        $dto->discount = $discount;
        $dto->total = '0';
        $dto->baseTotal = '0';
        $dto->tax = '0';

        $this->assertFormData($this->factory->create(QuoteType::class, new QuoteFormDTO()), $formData, $dto);
    }

    /**
     * @return array<FormExtensionInterface>
     */
    #[Override]
    protected function getExtensions(): array
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig
            ->shouldReceive('getCurrency')
            ->zeroOrMoreTimes()
            ->andReturn(new Currency('USD'));

        $systemConfig
            ->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturn('random_number');

        $featureGate = $this->createStub(FeatureGate::class);
        $featureGate->method('isEnabled')
            ->willReturn(true);

        $type = new QuoteType($systemConfig, new BillingIdGenerator(new ServiceLocator(['random_number' => static fn () => new class() {
            public function generate(): string
            {
                return '10';
            }
        }]), $systemConfig), $featureGate, $this->taxAvailability());
        $itemType = new ItemType($this->taxAvailability());

        $customFieldsType = new CustomFieldValueCollectionType(
            M::mock(CustomFieldRepository::class, ['findByTargetOrdered' => []]),
            M::mock(CustomFieldValueRepository::class, ['findForRecord' => []]),
            new CustomFieldTypeResolver(),
            $this->createStub(EntityManagerInterface::class),
        );

        return [
            new PreloadedExtension([$type, $itemType, new DiscountType($systemConfig), $customFieldsType], [
                ChoiceType::class => [
                    new AutocompleteChoiceTypeExtension(new ChecksumCalculator($_SERVER['AUGIAS_APP_SECRET'])),
                ],
            ]),
        ];
    }

    /**
     * Whether the tax field is offered is a service now, joining "any rates
     * configured" with "the company is liable for VAT". These tests are about
     * the liable case, so it comes from the container rather than a stub.
     */
    private function taxAvailability(): TaxAvailability
    {
        $availability = self::getContainer()->get(TaxAvailability::class);
        self::assertInstanceOf(TaxAvailability::class, $availability);

        return $availability;
    }
}
