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

use Augias\CoreBundle\Pdf\Generator;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Listener\Mailer\CreditNotePdfListener;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;

final class CreditNotePdfListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAttachesTheCreditNote(): void
    {
        $creditNote = new CreditNote();

        $twig = M::mock(Environment::class);
        $twig->shouldReceive('render')
            ->once()
            ->with('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote])
            ->andReturn('<p>Credit note</p>');

        $pdf = M::mock(Generator::class);
        $pdf->shouldReceive('canPrintPdf')->andReturnTrue();
        $pdf->shouldReceive('generate')->with('<p>Credit note</p>')->andReturn('PDF: credit note');

        $message = new CreditNoteEmail($creditNote);
        new CreditNotePdfListener($pdf, $twig)(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertEquals(
            [new DataPart('PDF: credit note', sprintf('credit_note_%s.pdf', $creditNote->getCreditNoteId()), 'application/pdf')],
            $message->getAttachments(),
        );
    }

    public function testEvents(): void
    {
        self::assertSame([MessageEvent::class], \array_keys(CreditNotePdfListener::getSubscribedEvents()));
    }
}
