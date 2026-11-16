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

namespace Augias\CoreBundle\Tests\Form\Extension;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Choice fields get the autocomplete widget by default, but only where it can
 * attach: radios and checkboxes render as a `<div>`, and the widget threw
 * "can only be used on an <input> or <select>" in the browser console on every
 * form that had some (new client, quote, invoice, profile notifications).
 */
final class AutocompleteExtensionTest extends KernelTestCase
{
    public function testADropdownGetsTheAutocompleteWidget(): void
    {
        $form = $this->factory()->create(ChoiceType::class, null, ['choices' => ['A' => 'a', 'B' => 'b']]);

        self::assertTrue($form->getConfig()->getOption('autocomplete'));
    }

    public function testRadiosAndCheckboxesDoNot(): void
    {
        $radios = $this->factory()->create(ChoiceType::class, null, ['choices' => ['A' => 'a', 'B' => 'b'], 'expanded' => true]);
        $checkboxes = $this->factory()->create(ChoiceType::class, null, ['choices' => ['A' => 'a', 'B' => 'b'], 'expanded' => true, 'multiple' => true]);

        self::assertFalse($radios->getConfig()->getOption('autocomplete'));
        self::assertFalse($checkboxes->getConfig()->getOption('autocomplete'));
    }

    public function testAnExplicitChoiceStillWins(): void
    {
        $form = $this->factory()->create(ChoiceType::class, null, ['choices' => ['A' => 'a'], 'autocomplete' => false]);

        self::assertFalse($form->getConfig()->getOption('autocomplete'));
    }

    private function factory(): FormFactoryInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class);
    }
}
