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

namespace Augias\CoreBundle\Billing;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use function is_string;
use function trim;

/**
 * The default terms of a new invoice or quote form (see DefaultTerms): put in
 * when the form opens, then kept in step with the client — a business or a
 * private individual — for as long as nobody edits them. Terms someone wrote,
 * or emptied, are never touched; an edited document neither.
 *
 * The using component has `$dto` (with `terms` and `client`), `$isEdit` and
 * the form's `$formValues`.
 */
trait AppliesDefaultTerms
{
    /**
     * The default terms last put in the form: terms that still read the same
     * were left alone, and may follow the client.
     */
    #[LiveProp(writable: true)]
    public ?string $appliedTerms = null;

    private function openWithDefaultTerms(DefaultTerms $defaultTerms, TermsDocument $document): void
    {
        if ($this->isEdit) {
            return;
        }

        $terms = (string) $this->dto->terms;

        // Terms brought along — from a quote, a copy: kept, and followed only
        // if they are a default themselves.
        if ('' !== trim($terms)) {
            $this->appliedTerms = $defaultTerms->isDefault($document, $terms) ? trim(DefaultTerms::normalise($terms)) : null;

            return;
        }

        $this->dto->terms = $defaultTerms->forClient($document, $this->dto->client instanceof Client ? $this->dto->client : null);
        $this->appliedTerms = $this->dto->terms;
    }

    private function followClientWithDefaultTerms(DefaultTerms $defaultTerms, TermsDocument $document, ClientRepository $clients): void
    {
        if ($this->isEdit || null === $this->appliedTerms) {
            return;
        }

        $current = $this->formValues['terms'] ?? null;

        if (! is_string($current) || trim(DefaultTerms::normalise($current)) !== $this->appliedTerms) {
            return;
        }

        $clientId = $this->formValues['client'] ?? null;
        $client = is_string($clientId) && '' !== $clientId ? $clients->find($clientId) : null;
        $terms = $defaultTerms->forClient($document, $client instanceof Client ? $client : null);

        if (null === $terms || $terms === $this->appliedTerms) {
            return;
        }

        $this->formValues['terms'] = $terms;
        $this->appliedTerms = $terms;
    }
}
