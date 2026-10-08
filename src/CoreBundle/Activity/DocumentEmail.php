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

namespace Augias\CoreBundle\Activity;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Journal\Journalled;

/**
 * An email that carries a document to the client. When it leaves, or fails
 * to, the document's history says so.
 */
interface DocumentEmail
{
    public function activityDocument(): Journalled;

    public function activityCompany(): Company;

    /**
     * What sets this email apart from the plain sending of the document, as a
     * key under `document_activity.detail.` (a reminder, say), or null.
     */
    public function activityDetail(): ?string;
}
