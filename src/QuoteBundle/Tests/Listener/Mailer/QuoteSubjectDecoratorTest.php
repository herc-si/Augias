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

namespace Augias\QuoteBundle\Tests\Listener\Mailer;

use Augias\QuoteBundle\Email\QuoteEmail;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Listener\Mailer\QuoteSubjectListener;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class QuoteSubjectDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testListener(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldReceive('get')
            ->with('quote/email_subject')
            ->andReturn('New Quote: #{id}');

        $listener = new QuoteSubjectListener($config, $this->translator());
        $quote = new Quote();
        $quote->setQuoteId('123');

        $message = new QuoteEmail($quote);
        $listener(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertSame('New Quote: #123', $message->getSubject());
    }

    /**
     * No subject of the company's own: the one of the app's language, with
     * the company's name (it was "New Quotation - #{id}" for everyone).
     */
    public function testWithoutASubjectOfItsOwnTheLanguageOfTheAppIsUsed(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldReceive('get')->with('quote/email_subject')->andReturn(null);
        $config->shouldReceive('get')->with('system/company/company_name')->andReturn('Acme SARL');

        $listener = new QuoteSubjectListener($config, $this->translator('fr'));
        $quote = new Quote();
        $quote->setQuoteId('123');

        $message = new QuoteEmail($quote);
        $listener(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertSame('Devis 123 – Acme SARL', $message->getSubject());
    }

    public function testEvents(): void
    {
        self::assertSame([MessageEvent::class], \array_keys(QuoteSubjectListener::getSubscribedEvents()));
    }

    private function translator(string $locale = 'en'): TranslatorInterface
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/email.' . $locale . '.yml', $locale, 'email');

        return $translator;
    }
}
