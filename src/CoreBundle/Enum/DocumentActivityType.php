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

namespace Augias\CoreBundle\Enum;

/**
 * What happened to a quote, an invoice or a credit note, as its history shows
 * it: what was done with it on our side, and what the client did with the
 * link they were sent.
 */
enum DocumentActivityType: string
{
    /** A step in its life: published, accepted, paid, cancelled... */
    case Status = 'status';

    /** An email carrying it left for the client. */
    case Sent = 'sent';

    /** An email carrying it could not be sent. */
    case SendFailed = 'send_failed';

    /** Someone opened the client's link to it. */
    case Viewed = 'viewed';

    /** Someone downloaded its PDF from the client's link. */
    case Downloaded = 'downloaded';

    /** The client accepted the quote from their link, under the name they gave. */
    case ClientAccepted = 'client_accepted';

    /** The client declined the quote from their link, with their reason if any. */
    case ClientDeclined = 'client_declined';

    public function translationKey(): string
    {
        return 'document_activity.type.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Status => 'tabler:circle-dot',
            self::Sent => 'tabler:send',
            self::SendFailed => 'tabler:alert-triangle',
            self::Viewed => 'tabler:eye',
            self::Downloaded => 'tabler:download',
            self::ClientAccepted => 'tabler:circle-check',
            self::ClientDeclined => 'tabler:circle-x',
        };
    }

    /**
     * Something the client did, as opposed to something done on our side.
     */
    public function byClient(): bool
    {
        return match ($this) {
            self::Viewed, self::Downloaded, self::ClientAccepted, self::ClientDeclined => true,
            default => false,
        };
    }

    /**
     * Only opening a link can be done by a program: a mail filter checks
     * every link it sees, but does not fill in a name and tick a box.
     */
    public function canBeAutomated(): bool
    {
        return self::Viewed === $this || self::Downloaded === $this;
    }
}
