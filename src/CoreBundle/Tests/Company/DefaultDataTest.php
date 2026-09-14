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

namespace Augias\CoreBundle\Tests\Company;

use Augias\CoreBundle\Company\DefaultData;
use Augias\CoreBundle\Config\SystemConfigProvider;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\CustomField\CustomField;
use Augias\InvoiceBundle\Config\ConfigProvider as InvoiceConfigProvider;
use Augias\MailerBundle\Config\ConfigProvider as MailerConfigProvider;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\QuoteBundle\Config\ConfigProvider as QuoteConfigProvider;
use Augias\SettingsBundle\Entity\Setting;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_map;
use function array_values;

final class DefaultDataTest extends TestCase
{
    use M\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function testDefaultData(): void
    {
        $registry = M::mock(ManagerRegistry::class);
        $entityManager = M::mock(EntityManagerInterface::class);

        $registry
            ->expects('getManager')
            ->andReturn($entityManager);

        $entityManager
            ->expects('persist')
            ->with(M::type(Setting::class))
            ->times(29);

        $entityManager->expects('persist')
            ->with(M::type(CustomField::class))
            ->times(3);

        $entityManager->expects('persist')
            ->with(M::type(PaymentMethod::class))
            ->times(3);

        $entityManager
            ->expects('flush')
            ->once();

        $defaultData = $this->defaultData($registry);

        $company = new Company();
        $company->setName('Test Company');

        $defaultData->__invoke($company, ['currency' => 'EUR']);
    }

    /**
     * The seeded payment methods and contact fields are plain data, written
     * once and editable afterwards — so they have to be written in the language
     * the company was created in, not the one the person clicking happens to be
     * using, and not English for everyone.
     */
    public function testSeededLabelsAreWrittenInTheCompanyLocale(): void
    {
        $french = $this->seed(['currency' => 'EUR', 'locale' => 'fr']);

        self::assertSame(
            ['Espèces', 'Virement bancaire', 'Crédit'],
            $this->names($french, PaymentMethod::class),
        );
        self::assertSame(
            ['E-mail secondaire', 'Téléphone', 'Téléphone mobile'],
            $this->names($french, CustomField::class),
        );
    }

    public function testSeededLabelsFallBackToTheDefaultLocale(): void
    {
        // No locale in the payload at all — an older caller, or an install that
        // never asked. It must still produce readable names.
        $none = $this->seed(['currency' => 'EUR']);

        self::assertSame(
            ['Cash', 'Bank Transfer', 'Credit'],
            $this->names($none, PaymentMethod::class),
        );

        $english = $this->seed(['currency' => 'EUR', 'locale' => 'en']);

        self::assertSame(
            ['Cash', 'Bank Transfer', 'Credit'],
            $this->names($english, PaymentMethod::class),
        );
    }

    /**
     * The caller reads the locale off the request, and Request::getLocale() can
     * return an empty string. That is not null, so it slipped past the `?? 'en'`
     * in SystemConfigProvider and was stored verbatim — leaving the company's
     * language setting blank instead of defaulting to English.
     */
    public function testBlankLocaleIsTreatedAsAbsent(): void
    {
        $persisted = $this->seed(['currency' => 'EUR', 'locale' => '']);

        self::assertSame(
            ['Cash', 'Bank Transfer', 'Credit'],
            $this->names($persisted, PaymentMethod::class),
        );

        $localeSetting = null;

        foreach ($persisted as $entity) {
            if ($entity instanceof Setting && $entity->getKey() === SystemConfig::LOCALE_CONFIG_PATH) {
                $localeSetting = $entity->getValue();
            }
        }

        self::assertSame('en', $localeSetting);
    }

    /**
     * @param array{currency: string, locale?: string|null} $data
     * @return list<object> everything that was persisted, in order
     */
    private function seed(array $data): array
    {
        $registry = M::mock(ManagerRegistry::class);
        $entityManager = M::mock(EntityManagerInterface::class);

        $registry->expects('getManager')->andReturn($entityManager);

        $persisted = [];
        $entityManager->allows('persist')->andReturnUsing(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $entityManager->allows('flush');

        $company = new Company();
        $company->setName('Test Company');

        $this->defaultData($registry)->__invoke($company, $data);

        return $persisted;
    }

    /**
     * @param list<object>         $persisted
     * @param class-string         $class
     * @return list<string>
     */
    private function names(array $persisted, string $class): array
    {
        $matching = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity::class === $class,
        ));

        return array_map(
            static fn (object $entity): string => $entity instanceof CustomField
                ? (string) $entity->getLabel()
                : (string) $entity->getName(),
            $matching,
        );
    }

    private function defaultData(ManagerRegistry $registry): DefaultData
    {
        return new DefaultData(
            $registry,
            [
                new SystemConfigProvider(),
                new InvoiceConfigProvider(),
                new QuoteConfigProvider(),
                new MailerConfigProvider(),
            ],
            $this->translator(),
        );
    }

    /**
     * A real translator rather than a stub: the point of these tests is that
     * the right catalogue is picked, which a stub returning its own argument
     * could never show.
     */
    private function translator(): TranslatorInterface
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());

        $translator->addResource('array', [
            'payment.method.default.cash' => 'Cash',
            'payment.method.default.bank_transfer' => 'Bank Transfer',
            'payment.method.default.credit' => 'Credit',
            'custom_field.default.additional_email' => 'Additional Email',
            'custom_field.default.phone' => 'Phone',
            'custom_field.default.mobile' => 'Mobile',
        ], 'en');

        $translator->addResource('array', [
            'payment.method.default.cash' => 'Espèces',
            'payment.method.default.bank_transfer' => 'Virement bancaire',
            'payment.method.default.credit' => 'Crédit',
            'custom_field.default.additional_email' => 'E-mail secondaire',
            'custom_field.default.phone' => 'Téléphone',
            'custom_field.default.mobile' => 'Téléphone mobile',
        ], 'fr');

        return $translator;
    }
}
