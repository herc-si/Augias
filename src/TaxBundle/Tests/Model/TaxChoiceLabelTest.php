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

namespace Augias\TaxBundle\Tests\Model;

use Augias\CoreBundle\Enum\CustomFieldType;
use Augias\CronBundle\Enum\ScheduleRecurringType;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Model\TaxChoiceLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Labels that used to reach the French interface in English: a tax category
 * glued on as "[exempt]", the custom field types, the recurring frequencies.
 */
#[CoversClass(TaxChoiceLabel::class)]
final class TaxChoiceLabelTest extends KernelTestCase
{
    public function testATaxCategoryReadsInFrench(): void
    {
        $tax = new Tax();
        $tax->setName('TVA');
        $tax->setRate(0);
        $tax->setType(Tax::TYPE_EXCLUSIVE);
        $tax->setCategory(TaxCategory::Exempt);

        self::assertSame('TVA (0%) [Exonérée]', TaxChoiceLabel::for($tax)->trans($this->translator(), 'fr'));
        self::assertSame('TVA (0%) [Exempt]', TaxChoiceLabel::for($tax)->trans($this->translator(), 'en'));
    }

    public function testAStandardRateCarriesNoCategory(): void
    {
        $tax = new Tax();
        $tax->setName('TVA 20 %');
        $tax->setRate(20);
        $tax->setType(Tax::TYPE_EXCLUSIVE);
        $tax->setCategory(TaxCategory::Standard);

        self::assertSame('TVA 20 % (20%)', TaxChoiceLabel::for($tax)->trans($this->translator(), 'fr'));
    }

    public function testOtherFormerlyEnglishLabelsReadInFrench(): void
    {
        $translator = $this->translator();

        self::assertSame('Hebdomadaire', ScheduleRecurringType::WEEKLY->trans($translator, 'fr'));
        self::assertSame('Choix unique', $translator->trans(CustomFieldType::SELECT->label(), [], null, 'fr'));
        self::assertSame('Créer un avoir', $translator->trans('credit_note.action.create', [], null, 'fr'));
        self::assertSame('Nouveau client', $translator->trans('client_mode.new', [], null, 'fr'));
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
