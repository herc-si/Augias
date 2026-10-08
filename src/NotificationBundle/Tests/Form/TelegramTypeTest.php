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

namespace Augias\NotificationBundle\Tests\Form;

use Augias\NotificationBundle\Form\Type\Transport\TelegramType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The chat id is the one field here people get wrong: the bot's own id, read
 * off the token, made Telegram refuse every message (preprod, 08/10/2026).
 */
#[CoversClass(TelegramType::class)]
final class TelegramTypeTest extends KernelTestCase
{
    public function testTheBotsOwnIdIsRefused(): void
    {
        $form = $this->submit('123456789:AAHsecret', ' 123456789 ');

        self::assertFalse($form->isValid());
        self::assertStringContainsString("C'est l'identifiant du bot", (string) $form->get('chat_id')->getErrors()->current()->getMessage());
    }

    public function testAPersonOrAGroupIsAccepted(): void
    {
        self::assertTrue($this->submit('123456789:AAHsecret', '512345678')->isValid());
        self::assertTrue($this->submit('123456789:AAHsecret', '-1001234567890')->isValid());
    }

    public function testTheChatIdFieldSaysWhereToFindIt(): void
    {
        $view = $this->submit('123456789:AAHsecret', '512345678')->createView();

        self::assertSame('notification.telegram.chat_id_help', $view['chat_id']->vars['help']);
        self::assertTrue($view['chat_id']->vars['help_html']);
        self::assertStringContainsString('getUpdates', self::getContainer()->get('translator')->trans('notification.telegram.chat_id_help', [], null, 'fr'));
    }

    /**
     * @return FormInterface<array{token: mixed, chat_id: mixed}>
     */
    private function submit(string $token, string $chatId): FormInterface
    {
        self::getContainer()->get('translator')->setLocale('fr');
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(TelegramType::class, null, ['validation_groups' => ['telegram'], 'csrf_protection' => false]);
        $form->submit(['token' => $token, 'chat_id' => $chatId]);

        return $form;
    }
}
