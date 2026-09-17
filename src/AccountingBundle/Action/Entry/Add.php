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
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Form\Type\LedgerEntryType;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\AttachmentStorage;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use function assert;
use function in_array;

/**
 * Adds an entry by hand — money that moved without an invoice or a supplier
 * bill behind it, and the reversing entries that correct a sealed period.
 *
 * The period is resolved the same way the automatic feeders resolve it, so a
 * hand-written entry dated inside a closed period is filed late into an open
 * one rather than refused: the books have to be able to record what actually
 * happened.
 */
final readonly class Add
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private ManagerRegistry $doctrine,
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private AccountingPeriodManager $periodManager,
        private CurrentCompany $currentCompany,
        private SystemConfig $systemConfig,
        private AttachmentStorage $storage,
    ) {
    }

    /**
     * @return array{form: FormView, book: LedgerBook, entry: LedgerEntry}|Response
     */
    #[Template('@AugiasAccounting/Default/entry_form.html.twig')]
    public function __invoke(string $book, Request $request): array | Response
    {
        $ledgerBook = LedgerBook::tryFrom($book);
        $profile = $this->profileProvider->forCompany();
        $regime = $this->registry->forProfile($profile);

        if (null === $ledgerBook || ! $regime instanceof RegimeInterface || ! in_array($ledgerBook, $regime->books($profile), true)) {
            throw new NotFoundHttpException('This company does not keep that book.');
        }

        $entry = new LedgerEntry()
            ->setBook($ledgerBook)
            ->setSource(LedgerEntrySource::Manual)
            ->setCurrencyCode($profile->currencyCode);

        if ($ledgerBook === LedgerBook::Revenue) {
            $entry->setActivityNature($profile->primaryActivity);
        }

        $form = $this->formFactory->create(LedgerEntryType::class, $entry, [
            'book' => $ledgerBook,
            'currency' => $this->systemConfig->getCurrency(),
            'vat_exempt' => $profile->vatExempt,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->doctrine->getManager();

            // Set here rather than left to the prePersist listener: that runs
            // during the flush, and the period has to be resolved — which needs
            // the company — before then.
            $entry->setCompany($this->currentCompany->require());

            $this->periodManager->assignPeriod($entry, $profile->declarationPeriodicity, $profile->fiscalYearStartMonth);

            foreach ($form->get('files')->getData() ?? [] as $file) {
                if ($file instanceof UploadedFile) {
                    $entry->addAttachment($this->storage->store($file, $entry->getCompany()));
                }
            }

            $entityManager->persist($entry);
            $entityManager->flush();

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', 'accounting.entry.flash.created');

            return new RedirectResponse($this->router->generate('_accounting_book', ['book' => $ledgerBook->value]));
        }

        return [
            'form' => $form->createView(),
            'book' => $ledgerBook,
            'entry' => $entry,
        ];
    }
}
