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

namespace Augias\InvoiceBundle\Tests\Form\Type;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Form\Type\CustomFieldValueCollectionType;
use Augias\CoreBundle\Form\Type\DiscountType;
use Augias\CoreBundle\Repository\CustomFieldRepository;
use Augias\CoreBundle\Repository\CustomFieldValueRepository;
use Augias\CoreBundle\Service\CustomField\CustomFieldTypeResolver;
use Augias\CoreBundle\Tests\FormTestCase;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Form\Type\ItemType;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Service\TaxAvailability;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as M;
use Money\Currency;
use Override;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Autocomplete\Checksum\ChecksumCalculator;
use Symfony\UX\Autocomplete\Form\AutocompleteChoiceTypeExtension;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

final class InvoiceTypeTest extends FormTestCase
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
            'notes' => $notes,
            'terms' => $terms,
            'total' => '0',
            'baseTotal' => '0',
            'invoiceDate' => '2021-01-01',
            'tax' => '0',
            'users' => [],
        ];

        $dto = new InvoiceFormDTO();
        $dto->clientMode = InvoiceClientMode::Existing;
        $dto->client = $client;
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
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');

        $this->assertFormData($this->factory->create(InvoiceType::class, new InvoiceFormDTO()), $formData, $dto);
    }

    public function testSubmitWithNewClient(): void
    {
        $notes = $this->faker->text();
        $terms = $this->faker->text();
        $discountValue = $this->faker->numberBetween(0, 100);

        $formData = [
            'clientMode' => 'new',
            'newClientName' => 'New Client',
            'newContactFirstName' => 'John',
            'newContactLastName' => 'Doe',
            'newContactEmail' => 'john@example.com',
            'discount' => [
                'value' => $discountValue,
                'type' => Discount::TYPE_PERCENTAGE,
            ],
            'lines' => [],
            'notes' => $notes,
            'terms' => $terms,
            'total' => '0',
            'baseTotal' => '0',
            'invoiceDate' => '2021-01-01',
            'tax' => '0',
        ];

        $dto = new InvoiceFormDTO();
        $dto->clientMode = InvoiceClientMode::NewClient;
        $dto->newClientName = 'New Client';
        $dto->newContactFirstName = 'John';
        $dto->newContactLastName = 'Doe';
        $dto->newContactEmail = 'john@example.com';
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
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');

        $this->assertFormData($this->factory->create(InvoiceType::class, new InvoiceFormDTO()), $formData, $dto);
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

        $invoiceType = new InvoiceType($systemConfig, $featureGate, $this->taxAvailability());
        $itemType = new ItemType($this->taxAvailability());

        $customFieldsType = new CustomFieldValueCollectionType(
            M::mock(CustomFieldRepository::class, ['findByTargetOrdered' => []]),
            M::mock(CustomFieldValueRepository::class, ['findForRecord' => []]),
            new CustomFieldTypeResolver(),
            $this->createStub(EntityManagerInterface::class),
        );

        return [
            // register the type instances with the PreloadedExtension
            new PreloadedExtension([
                $invoiceType,
                $itemType,
                new DiscountType($systemConfig),
                $customFieldsType,
                new BaseEntityAutocompleteType($this->createStub(UrlGeneratorInterface::class))
            ], [
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
