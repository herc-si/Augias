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

namespace Augias\TaxBundle\Tests\Twig\Components;

use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use Augias\TaxBundle\Twig\Components\CompanyTaxIdentifiers;
use PHPUnit\Framework\Attributes\CoversClass;
use function array_map;
use function sort;

/**
 * SIRET, SIREN and VAT number in fields of their own; everything else in the
 * list; all of it saved as the identifiers invoices and e-invoices read.
 */
#[CoversClass(CompanyTaxIdentifiers::class)]
final class CompanyTaxIdentifiersTest extends LiveComponentTest
{
    public function testTheFieldsShowWhatIsSaved(): void
    {
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '12345678900012']);
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'RCS', 'value' => 'RCS Paris 123']);

        $rendered = $this->createLiveComponent(CompanyTaxIdentifiers::class, client: $this->client)
            ->actingAs($this->getUser())
            ->render()
            ->toString();

        self::assertMatchesRegularExpression('/name="company_tax_identifiers\[siret\]"[^>]*value="12345678900012"/', $rendered);
        self::assertStringContainsString('RCS Paris 123', $rendered);
    }

    public function testSavingKeepsThemAsIdentifiers(): void
    {
        $saved = TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '12345678900012']);

        $component = $this->createLiveComponent(CompanyTaxIdentifiers::class, client: $this->client)->actingAs($this->getUser());
        $component->set('company_tax_identifiers.siret', '98765432100019');
        $component->set('company_tax_identifiers.vatNumber', 'FR12987654321');
        $component->call('save');

        $identifiers = self::getContainer()->get(TaxIdentifierRepository::class)->findCompanyIdentifiers($this->company->getId());
        $values = array_map(static fn ($identifier): string => $identifier->getLabel() . '=' . $identifier->getValue(), $identifiers);
        sort($values);

        self::assertSame(['SIRET=98765432100019', 'TVA intracommunautaire=FR12987654321'], $values);
        self::assertTrue(
            $identifiers[0]->getId()?->equals($saved->getId()) || $identifiers[1]->getId()?->equals($saved->getId()),
            'The saved SIRET is changed in place, not replaced.',
        );
    }

    /**
     * With e-invoicing on, a company in franchise needs its SIRET but not a
     * VAT number it usually does not have.
     */
    public function testAFranchiseCompanyIsNotAskedForAVatNumber(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');
        $config->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');

        $component = $this->createLiveComponent(CompanyTaxIdentifiers::class, client: $this->client)->actingAs($this->getUser());
        $component->set('company_tax_identifiers.siret', '12345678900012');
        $component->call('save');

        self::assertCount(1, self::getContainer()->get(TaxIdentifierRepository::class)->findCompanyIdentifiers($this->company->getId()));
    }
}
