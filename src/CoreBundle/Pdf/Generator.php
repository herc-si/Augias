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

namespace Augias\CoreBundle\Pdf;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Psr\Log\LoggerInterface;

/**
 * @see \Augias\CoreBundle\Tests\Pdf\GeneratorTest
 */
class Generator
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param bool $protect Encrypts the PDF, restricting it to printing only.
     *                       Must be disabled when the output will be read back by
     *                       a third-party tool that can't handle encrypted PDFs —
     *                       e.g. FPDI, used by horstoeko/zugferd to merge a Factur-X
     *                       XML into an invoice PDF (see FacturXInvoiceBuilder).
     *
     * @throws MpdfException
     */
    public function generate(string $html, bool $protect = true): string
    {
        $mpdf = new Mpdf([
            'tempDir' => $this->cacheDir . '/pdf',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 25,
            'margin_header' => 10,
            'margin_footer' => 10,
            'default_font' => 'helvetica',
            // A table kept in one piece (the totals and terms at the end of a
            // document) that does not fit what is left of the page moves to
            // the next one. By default mPDF first tries shrinking it, up to
            // 1.4 times: the totals came out in small print, or cut from
            // their terms (08/10/2026).
            'shrink_tables_to_fit' => 0,
        ]);

        $mpdf->allow_charset_conversion = false;
        $mpdf->showWatermarkText = true;
        $mpdf->SetDisplayMode('fullpage');

        if ($protect) {
            $mpdf->SetProtection(['print']);
        }

        $mpdf->setLogger($this->logger);
        $mpdf->WriteHTML($html);

        return $mpdf->Output(null, Destination::STRING_RETURN);
    }

    public function canPrintPdf(): bool
    {
        return \extension_loaded('mbstring') && \extension_loaded('gd');
    }
}
