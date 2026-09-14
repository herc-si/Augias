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

namespace Augias\CoreBundle\Enum\Menu;

/**
 * Sidebar order, highest first:
 *
 *     Tableau de bord — Tiers — Prestation — Catalogue — Paiement — Comptabilité — Système
 *
 * The value is a *builder* priority, not a position: it decides the order the
 * menu builders run in, and items land in the sidebar in the order they are
 * added.
 *
 * That has one consequence worth stating plainly. Quotes, sales invoices and
 * purchase invoices no longer sit at the top level — they are children of the
 * Prestation section built by {@see \Augias\CoreBundle\Menu\PrestationMenu}.
 * Their three values below therefore only order them *within* that section, and
 * all three must stay lower than {@see self::PRIORITY_PRESTATION}, or their
 * builders would run before the section exists and they would fall back to the
 * top level.
 */
enum MenuPriority: int
{
    case PRIORITY_DASHBOARD = 100;

    /** Clients and suppliers are one "Tiers" record — see Client::$isClient / $isSupplier. */
    case PRIORITY_CLIENT = 90;

    /** The section holding the three billing documents. */
    case PRIORITY_PRESTATION = 80;

    case PRIORITY_QUOTE = 79;

    case PRIORITY_INVOICE = 78;

    case PRIORITY_BILL = 77;

    case PRIORITY_CREDIT_NOTE = 76;

    case PRIORITY_CATALOG = 70;

    case PRIORITY_PAYMENT = 60;

    case PRIORITY_ACCOUNTING = 50;

    case PRIORITY_SYSTEM = 10;
}
