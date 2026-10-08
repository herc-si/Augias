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

namespace Augias\CoreBundle\Twig\Extension;

use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Journal\Journalled;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Override;
use Symfony\Component\Uid\Ulid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DocumentActivityExtension extends AbstractExtension
{
    public function __construct(
        private readonly DocumentActivityRepository $repository,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_activity', $this->history(...)),
        ];
    }

    /**
     * @return list<DocumentActivity>
     */
    public function history(Journalled $document): array
    {
        $id = $document->getId();

        if (! $id instanceof Ulid) {
            return [];
        }

        return $this->repository->forDocument($document->journalKind(), $id);
    }
}
