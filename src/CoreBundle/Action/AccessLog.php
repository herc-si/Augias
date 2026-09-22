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

namespace Augias\CoreBundle\Action;

use Augias\AppMode;
use Augias\CoreBundle\Entity\OperatorAccess;
use Augias\CoreBundle\Repository\OperatorAccessRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shows a company who opened its file, and why.
 *
 * The page exists on every install, hosted or not. On a self-hosted one it has
 * nothing to show — there is no operator but the owner — and says so plainly
 * rather than being hidden: "nobody has" is the answer the page is for, and a
 * page that only appears once there is bad news is worth less than one that
 * was always there.
 *
 * Reading the page is not itself an access to record. It says who looked, not
 * what they saw, and a log that logs being read fills with itself.
 */
final readonly class AccessLog
{
    public function __construct(
        private OperatorAccessRepository $repository,
        #[Autowire(param: 'app_mode')]
        private string $appMode,
    ) {
    }

    /**
     * @return array{records: list<OperatorAccess>, hosted: bool}
     */
    #[Template('@AugiasCore/AccessLog/index.html.twig')]
    public function __invoke(): array
    {
        return [
            'records' => $this->repository->recent(),
            'hosted' => AppMode::tryFrom($this->appMode) === AppMode::SAAS,
        ];
    }
}
