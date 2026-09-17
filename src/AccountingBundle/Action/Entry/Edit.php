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

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Form\Type\LedgerEntryType;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\LedgerLockDate;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use function assert;

/**
 * Corrects an entry while its period is still open.
 *
 * An entry the books have been shut on is not editable and never reaches the
 * form: it is sent back to its book with a word about why. Shut means sealed,
 * or sitting in a period that ended before the lock date. This is the one place
 * a user meets that rule, so it says so rather than hiding the button and
 * leaving them to guess.
 *
 * An automatic entry opens in a form where only the bookkeeping-side fields are
 * enabled — see {@see LedgerEntryType}.
 */
final readonly class Edit
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private ManagerRegistry $doctrine,
        private SystemConfig $systemConfig,
        private LedgerLockDate $lockDate,
        private AccountingProfileProvider $profileProvider,
    ) {
    }

    /**
     * @return array{form: FormView, book: LedgerBook, entry: LedgerEntry}|Response
     */
    #[Template('@AugiasAccounting/Default/entry_form.html.twig')]
    public function __invoke(LedgerEntry $entry, Request $request): array | Response
    {
        $session = $request->getSession();
        assert($session instanceof Session);

        if ($entry->isLocked() || $this->lockDate->shuts($entry)) {
            $session->getFlashBag()->add('warning', 'accounting.entry.flash.locked');

            return new RedirectResponse(
                $this->router->generate('_accounting_book', ['book' => $entry->getBook()->value]),
            );
        }

        $form = $this->formFactory->create(LedgerEntryType::class, $entry, [
            'book' => $entry->getBook(),
            'mirrors_a_payment' => $entry->getSource()->isAutomatic(),
            'currency' => $this->systemConfig->getCurrency(),
            'vat_exempt' => $this->profileProvider->forCompany()->vatExempt,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->doctrine->getManager()->flush();

            $session->getFlashBag()->add('success', 'accounting.entry.flash.updated');

            return new RedirectResponse(
                $this->router->generate('_accounting_book', ['book' => $entry->getBook()->value]),
            );
        }

        return [
            'form' => $form->createView(),
            'book' => $entry->getBook(),
            'entry' => $entry,
        ];
    }
}
