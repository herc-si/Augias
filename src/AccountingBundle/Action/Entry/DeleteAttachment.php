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

namespace Augias\AccountingBundle\Action\Entry;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Service\LedgerLockDate;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Removes a supporting document from an entry.
 *
 * Refused once the books have been shut on the entry — sealed, or in a period
 * before the lock date. The seal is a statement that what was recorded then can
 * no longer change, and an entry whose evidence can still be taken away is not
 * sealed in any sense worth having. The same rule already governs the entry
 * itself; this is the other half of it.
 *
 * The file goes with the row, through
 * {@see \Augias\AccountingBundle\Listener\Doctrine\AttachmentFileListener}.
 */
final readonly class DeleteAttachment
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RouterInterface $router,
        private LedgerLockDate $lockDate,
    ) {
    }

    public function __invoke(EntryAttachment $attachment, Request $request, Session $session): Response
    {
        $entry = $attachment->getEntry();
        $back = new RedirectResponse(
            $this->router->generate('_accounting_entry_edit', ['id' => $entry->getId()]),
        );

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken('delete_attachment' . $attachment->getId(), $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

            return $back;
        }

        if ($entry->isLocked() || $this->lockDate->shuts($entry)) {
            $session->getFlashBag()->add('warning', 'accounting.entry.flash.locked');

            return $back;
        }

        $entityManager = $this->doctrine->getManager();
        $entry->removeAttachment($attachment);
        $entityManager->remove($attachment);
        $entityManager->flush();

        $session->getFlashBag()->add('success', 'accounting.entry.flash.attachment_deleted');

        return $back;
    }
}
