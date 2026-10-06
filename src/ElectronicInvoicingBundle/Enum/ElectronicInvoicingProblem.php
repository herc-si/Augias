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

namespace Augias\ElectronicInvoicingBundle\Enum;

use function sprintf;

/**
 * What can go wrong with electronic invoicing that someone has to act on —
 * each told by {@see \Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoicingProblemNotification}.
 * A rejection and a dispute have their own notifications.
 */
enum ElectronicInvoicingProblem: string
{
    /** The invoice did not reach the platform. */
    case SendFailed = 'send_failed';

    /** The client's platform suspended the invoice (fr:208): it waits for what is missing. */
    case Suspended = 'suspended';

    /** The platform no longer vouches for the company: electronic invoicing is stopped. */
    case AccountNotVerified = 'account_not_verified';

    /** The connection to the platform was lost: the company has to connect again. */
    case Disconnected = 'disconnected';

    /** A sale to a private individual, or its payment, could not be reported. */
    case ReportFailed = 'report_failed';

    /** The payment of an invoice sent electronically could not be passed on (fr:212). */
    case PaymentStatusFailed = 'payment_status_failed';

    /** A purchase received electronically was paid, and its supplier could not be told (fr:211). */
    case PaymentSentFailed = 'payment_sent_failed';

    public function translationKey(string $part): string
    {
        return sprintf('einvoicing.notification_problem.%s.%s', $this->value, $part);
    }
}
