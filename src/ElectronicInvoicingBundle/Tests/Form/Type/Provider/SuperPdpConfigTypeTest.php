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

namespace Augias\ElectronicInvoicingBundle\Tests\Form\Type\Provider;

use Augias\ElectronicInvoicingBundle\Form\Type\Provider\SuperPdpConfigType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The secret is written once and never read back: not rendered, not wiped by
 * saving the page again, and still required when there is none.
 */
#[CoversClass(SuperPdpConfigType::class)]
final class SuperPdpConfigTypeTest extends KernelTestCase
{
    /**
     * What happened on the dev instance on 25/09/2026: the page saved again
     * with the field left empty, and the secret was gone.
     */
    public function testSavingAgainWithoutRetypingKeepsTheSecret(): void
    {
        $form = $this->form(['client_id' => 'id', 'client_secret' => 's3cret']);
        $form->submit(['client_id' => 'id', 'client_secret' => '']);

        self::assertTrue($form->isValid());
        self::assertSame(['client_id' => 'id', 'client_secret' => 's3cret'], $form->getData());
    }

    public function testATypedSecretReplacesTheSavedOne(): void
    {
        $form = $this->form(['client_id' => 'id', 'client_secret' => 's3cret']);
        $form->submit(['client_id' => 'id', 'client_secret' => 'new-one']);

        self::assertSame('new-one', $form->getData()['client_secret']);
    }

    public function testASecretIsRequiredWhenNoneIsSaved(): void
    {
        $form = $this->form(null);
        $form->submit(['client_id' => 'id', 'client_secret' => '']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('client_secret')->getErrors());
    }

    /**
     * Rendered, it also sat in clear in the live component's state.
     */
    public function testTheSavedSecretIsNeverRendered(): void
    {
        $view = $this->form(['client_id' => 'id', 'client_secret' => 's3cret'])->createView();

        self::assertSame('', $view['client_secret']->vars['value']);
    }

    /**
     * @param array<string, string>|null $saved
     *
     * @return FormInterface<mixed>
     */
    private function form(?array $saved): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)
            ->create(SuperPdpConfigType::class, $saved, ['csrf_protection' => false]);
    }
}
