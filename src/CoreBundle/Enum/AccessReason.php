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
 * Why someone operating a deployment read a company's data.
 *
 * A fixed list rather than a sentence typed at the time, for two reasons that
 * pull the same way: the reason is shown to the customer whose file was read,
 * so it has to be translated at display time rather than frozen in the row;
 * and an account of access is only worth something if the vocabulary it is
 * drawn from can be read in advance — which, here, means reading it in the
 * open-source application the customer was given.
 *
 * Nothing in this repository writes these. The operator tooling of a hosted
 * deployment does, and it may be a version ahead of the application it runs
 * beside, so {@see \Augias\CoreBundle\Entity\OperatorAccess} stores the value
 * as text and a reason this list does not know is shown as it was written
 * rather than hidden.
 */
enum AccessReason: string
{
    /** The list of every company on the deployment, which names them all. */
    case TenantList = 'tenant_list';

    /** The list of accounts, which names the people in every company. */
    case UserList = 'user_list';

    /** The list of plans and who is on which. */
    case PlanList = 'plan_list';

    /**
     * The record of who has been signing in, read across companies. It names
     * the people with access to a company and when they used it, which is the
     * company's data even though the rows are about accounts.
     */
    case SignInList = 'sign_in_list';

    /**
     * The key under which this reason is translated for the customer.
     */
    public function translationKey(): string
    {
        return 'access_log.reason.' . $this->value;
    }
}
