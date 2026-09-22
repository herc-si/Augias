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

namespace Augias\AccountingBundle\Action;

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CompanyBooks;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\CoreBundle\Entity\Company;
use DateTimeImmutable;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One statutory book, with the period that is currently open above it.
 *
 * A book the company's regime does not require is not shown at all rather than
 * shown empty: a services-only micro-entrepreneur has no purchase register to
 * keep, and an empty page implying otherwise would be an invented obligation.
 */
final readonly class Book
{
    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private CompanyBooks $books,
        private AccountingPeriodRepository $periodRepository,
        private CurrentCompany $currentCompany,
    ) {
    }

    /**
     * @return array{
     *     book: LedgerBook,
     *     profile: AccountingProfile,
     *     period: AccountingPeriod|null,
     *     ledger_entry_grid_context: array{book: string}
     * }
     */
    #[Template('@AugiasAccounting/Default/book.html.twig')]
    public function __invoke(string $book): array
    {
        $ledgerBook = LedgerBook::tryFrom($book);
        $profile = $this->profileProvider->forCompany();

        if (! $this->books->keeps($profile, $ledgerBook)) {
            throw new NotFoundHttpException('This company does not keep that book.');
        }

        return [
            'book' => $ledgerBook,
            'profile' => $profile,
            'period' => $this->currentPeriod($profile),
            'ledger_entry_grid_context' => ['book' => $ledgerBook->value],
        ];
    }

    /**
     * The period today falls in, when it exists. Periods are created by the
     * first entry filed into them, so a company that has booked nothing this
     * quarter has none — and nothing to close.
     */
    private function currentPeriod(AccountingProfile $profile): ?AccountingPeriod
    {
        $company = $this->currentCompany->get();

        if (! $company instanceof Company) {
            return null;
        }

        return $this->periodRepository->findForDate(
            $company,
            $profile->declarationPeriodicity,
            new DateTimeImmutable('today'),
            $profile->fiscalYearStartMonth,
        );
    }
}
