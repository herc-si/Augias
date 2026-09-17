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

namespace Augias\AccountingBundle\Listener\Doctrine;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Service\AttachmentStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Takes the file with the row.
 *
 * Deleting an attachment, or the entry it hangs off, removes the row; nothing
 * about that removes the document from disk. Hooked here rather than in the
 * actions because there are two ways to lose an attachment — on its own, or
 * with its entry — and a directory quietly filling up with documents belonging
 * to entries that no longer exist is the kind of thing nobody notices for a
 * year.
 *
 * postRemove, not preRemove: the file goes once the delete has actually gone
 * through. A transaction that rolls back afterwards would otherwise leave a row
 * pointing at a file this had already thrown away.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\EntryAttachmentTest
 */
#[AsEntityListener(event: Events::postRemove, entity: EntryAttachment::class)]
final readonly class AttachmentFileListener
{
    public function __construct(
        private AttachmentStorage $storage,
    ) {
    }

    public function postRemove(EntryAttachment $attachment): void
    {
        $this->storage->remove($attachment);
    }
}
