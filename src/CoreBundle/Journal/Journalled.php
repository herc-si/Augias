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

namespace Augias\CoreBundle\Journal;

use Augias\CoreBundle\Enum\RecordKind;
use Symfony\Component\Uid\Ulid;

/**
 * A record whose opening belongs in the account journal.
 *
 * An interface rather than a list of classes held in CoreBundle, for two
 * reasons. A bundle decides for itself whether its records are the kind a
 * person opens one at a time — CoreBundle has no business knowing that
 * invoices are and payment methods are not — and the listener then needs no
 * map to maintain: it looks at what the controller was handed and asks it.
 *
 * @see \Augias\CoreBundle\Listener\RecordAccessListener
 */
interface Journalled
{
    /**
     * Null while the record has never been saved, which is a state the journal
     * has nothing to say about: it records what was opened, and what was never
     * written cannot have been.
     */
    public function getId(): ?Ulid;

    public function journalKind(): RecordKind;

    /**
     * How the record is named in the journal, captured when it is opened.
     *
     * Stored rather than resolved later: an invoice can be deleted, a client
     * renamed, and a journal that changes its account of the past with them
     * would be worth less than one that says what was on screen that day.
     */
    public function journalLabel(): string;
}
