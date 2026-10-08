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

namespace Augias\InvoiceBundle\Tests\Listener\Mailer;

use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Listener\Mailer\InvoiceSubjectListener;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceSubjectDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testListener(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldReceive('get')
            ->with('invoice/email_subject')
            ->andReturn('New Invoice: #{id}');

        $listener = new InvoiceSubjectListener($config, $this->translator());
        $invoice = new Invoice();
        $invoice->setInvoiceId('123');

        $message = new InvoiceEmail($invoice);
        $listener(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertSame('New Invoice: #123', $message->getSubject());
    }

    /**
     * No subject of the company's own: the one of the app's language, with
     * the company's name (it was "New Quotation - #{id}" for everyone).
     */
    public function testWithoutASubjectOfItsOwnTheLanguageOfTheAppIsUsed(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldReceive('get')->with('invoice/email_subject')->andReturn(null);
        $config->shouldReceive('get')->with('system/company/company_name')->andReturn('Acme SARL');

        $listener = new InvoiceSubjectListener($config, $this->translator('fr'));
        $invoice = new Invoice();
        $invoice->setInvoiceId('123');

        $message = new InvoiceEmail($invoice);
        $listener(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertSame('Facture 123 – Acme SARL', $message->getSubject());
    }

    public function testEvents(): void
    {
        self::assertSame([MessageEvent::class], \array_keys(InvoiceSubjectListener::getSubscribedEvents()));
    }

    private function translator(string $locale = 'en'): TranslatorInterface
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/email.' . $locale . '.yml', $locale, 'email');

        return $translator;
    }
}
