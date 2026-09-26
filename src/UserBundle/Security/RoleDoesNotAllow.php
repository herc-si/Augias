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

namespace Augias\UserBundle\Security;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A page or an action the member's role does not open.
 *
 * Its message is shown as it is — on the 403 page, in the API's answer — so
 * it says which role and what it leaves out, in the member's language.
 */
final class RoleDoesNotAllow extends AccessDeniedHttpException
{
}
