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

namespace Augias\CoreBundle\Action\Category;

use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function trim;

/**
 * The "+" beside a category dropdown: creates the category without leaving
 * the form being filled in, and answers with what the dropdown needs to
 * offer and select it.
 *
 * A name already in the shared list is reused rather than refused - the
 * list is unique by name - and gains the usage it was asked for, since the
 * dropdown it came from would otherwise still not offer it.
 */
final class QuickAdd extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isCsrfTokenValid('category_quick_add', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('category.quick_add.failed')], Response::HTTP_BAD_REQUEST);
        }

        $usage = CategoryUsage::tryFrom((string) $request->request->get('usage'));

        if (! $usage instanceof CategoryUsage) {
            return new JsonResponse(['error' => $this->translator->trans('category.quick_add.failed')], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) $request->request->get('name'));
        $category = $this->categoryRepository->findOneBy(['name' => $name]);
        $status = Response::HTTP_OK;

        if (! $category instanceof Category) {
            $category = new Category()->setName($name);
            $status = Response::HTTP_CREATED;
        }

        $category->setUsage($usage, true);

        $violations = $this->validator->validate($category);

        if ($violations->count() > 0) {
            return new JsonResponse(['error' => (string) $violations->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return new JsonResponse(['id' => (string) $category->getId(), 'name' => $category->getName()], $status);
    }
}
