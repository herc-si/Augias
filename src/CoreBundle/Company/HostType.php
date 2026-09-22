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

namespace Augias\CoreBundle\Company;

enum HostType: string
{
    case DefaultHost = 'default';
    case CustomDomain = 'custom_domain';

    /**
     * A host this deployment answers on for its own purposes, which is not a
     * tenant's.
     *
     * It exists so such a host can be told apart from an unknown one, which is
     * refused, and from a tenant's custom domain, which would scope the request
     * to that tenant. A reserved host is neither: nothing about the request is
     * a tenant's, and nothing should be made to be.
     */
    case Reserved = 'reserved';

    case Unknown = 'unknown';
}
