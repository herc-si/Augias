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

namespace Augias\CoreBundle\Export\Attachment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes files to a company's export: the documents as they were
 * issued, the receipts kept beside them. The rows say what happened; these
 * are what a company has to keep, and what it takes with it when it leaves.
 *
 * Called in the exported company's context, so the company filter already
 * limits what the provider's queries return.
 */
#[AutoconfigureTag(self::TAG)]
interface ExportAttachmentProvider
{
    public const string TAG = 'augias.export_attachment_provider';

    /**
     * @return iterable<ExportAttachment>
     */
    public function attachments(): iterable;
}
