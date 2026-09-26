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

namespace Augias\SupplierBundle\Action;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A supplier is a {@see Client} flagged {@see Client::isSupplier()}, so
 * "deleting" it here must not destroy the party if it's also a real client
 * (`isClient` true) — it only clears the supplier role in that case. Only a
 * pure supplier (`isClient` false) is actually removed.
 */
final readonly class Delete
{
    public function __construct(
        private ClientRepository $clientRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Client $client, Request $request, Session $session): Response
    {
        $token = $request->request->get('_token');

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken('delete' . $client->getId(), $token))) {
            $session->getFlashBag()->add('danger', $this->translator->trans('Invalid CSRF token'));

            return new RedirectResponse($this->router->generate('_suppliers_view', ['id' => $client->getId()]));
        }

        if ($client->isClient()) {
            $client->setIsSupplier(false);
            $this->entityManager->flush();

            $session->getFlashBag()->add('success', $this->translator->trans('supplier.remove_role_success'));

            return new RedirectResponse($this->router->generate('_clients_view', ['id' => $client->getId()]));
        }

        try {
            $this->clientRepository->delete($client);
        } catch (DocumentMustBeKept $refusal) {
            $session->getFlashBag()->add('danger', $refusal->trans($this->translator));

            return new RedirectResponse($this->router->generate('_suppliers_view', ['id' => $client->getId()]));
        }

        $session->getFlashBag()->add('success', $this->translator->trans('supplier.delete_success'));

        return new RedirectResponse($this->router->generate('_suppliers_index'));
    }
}
