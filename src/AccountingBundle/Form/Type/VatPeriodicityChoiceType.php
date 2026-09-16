<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Form\Type;

use Augias\AccountingBundle\Enum\PeriodType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * How often VAT is declared, when that is not the rhythm the books are kept on.
 *
 * Empty — the default — means the two coincide, which is what most companies
 * want and what the module did before this existed.
 *
 * {@see PeriodType::Year} used to be offered here, and nowhere else, for the
 * régime réel simplifié: it wanted one VAT return a year on the CA12 while
 * URSSAF still wanted turnover every quarter. That regime is abolished on
 * 1 January 2027 by article 38 of the loi de finances pour 2025, and the CA12
 * goes with it — leaving nothing that declares VAT annually. The option is
 * gone rather than left to rot as a choice no one can lawfully make.
 *
 * A month or a quarter still differ from the books' rhythm, and that remains
 * safe: a VAT cycle only groups figures for a return, the books are still
 * sealed on the rhythm above it.
 *
 * @extends AbstractType<string>
 */
final class VatPeriodicityChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'placeholder' => 'accounting.settings.vat_periodicity.same_as_books',
            'choices' => [
                PeriodType::Month->translationKey() => PeriodType::Month->value,
                PeriodType::Quarter->translationKey() => PeriodType::Quarter->value,
            ],
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
