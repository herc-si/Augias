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

/*
 * ---------------------------------------------------------------------------
 * REGULATORY DATA — CHECKED 2026-09-16, AND PERISHABLE
 * ---------------------------------------------------------------------------
 *
 * Every figure below was checked against an official source on 2026-09-16, and
 * every entry carries `verified => true` to say so. Three errors came out of
 * that pass: the 2026 ceilings were missing entirely, the contribution rates
 * were dated six months early, and the ACRE reduction had halved.
 *
 * Sources used, in preference to any explanatory page:
 *   - BOFiP barème BOI-BAREME-000036            (ceilings, VAT franchise)
 *   - arrêté du 27 janvier 2026                 (triennial revaluation)
 *   - décret n° 2024-484 du 30 mai 2024         (contribution rates, 2024-2026)
 *   - urssaf.fr / autoentrepreneur.urssaf.fr    (training levy, ACRE)
 *
 * Read the barème, not the vulgarisation. At the time of that pass,
 * impots.gouv.fr's own page on VAT regimes still showed the 2023-2025
 * thresholds and made no mention of the régime simplifié being abolished.
 *
 * These figures are revised by each finance act and are sometimes changed
 * mid-year — one recent change, the single 25,000 EUR VAT threshold, was voted,
 * suspended twice, and finally abandoned. So `verified => true` means "checked
 * on the date above", not "correct forever". A new entry must be checked before
 * it is flagged; FrenchRateTableTest fails if one is not. The UI shows the rate
 * vintage next to every computed figure and every declaration line stays
 * editable, so a stale rate here remains a correctable annoyance rather than a
 * wrong filing.
 *
 * Shape
 * -----
 * Both sections are lists of dated entries, ordered oldest first. A lookup
 * takes the last entry whose `effective_from` is on or before the date being
 * resolved — so a period is always measured against the rules in force at the
 * time, not today's.
 *
 * Money is in minor units (cents). Rates are percentages, as strings, to keep
 * them out of floating point.
 */

return [
    'thresholds' => [
        [
            'effective_from' => '2023-01-01',
            'verified' => true,
            // Plafonds du régime micro (CA annuel), période triennale 2023-2025.
            'micro_ceiling' => [
                'sale_of_goods' => 18_870_000,
                'services_bic' => 7_770_000,
                'services_bnc' => 7_770_000,
            ],
            // Franchise en base de TVA — seuil de base.
            'vat_franchise_base' => [
                'sale_of_goods' => 8_500_000,
                'services_bic' => 3_750_000,
                'services_bnc' => 3_750_000,
            ],
            // Seuil majoré (tolérance) : au-delà, la TVA devient due.
            'vat_franchise_tolerance' => [
                'sale_of_goods' => 9_350_000,
                'services_bic' => 4_125_000,
                'services_bnc' => 4_125_000,
            ],
        ],
        [
            // Revalorisation triennale, applicable 2026-2028. Sans cette entrée
            // une période de 2026 était mesurée contre le plafond 2023-2025,
            // soit 14 400 € de moins pour la vente et 5 900 € pour les services.
            'effective_from' => '2026-01-01',
            'verified' => true,
            'micro_ceiling' => [
                'sale_of_goods' => 20_310_000,
                'services_bic' => 8_360_000,
                'services_bnc' => 8_360_000,
            ],
            // Inchangés : le seuil unique à 25 000 € a été abandonné par la loi
            // n° 2025-1044 du 3 novembre 2025, après deux ans de suspensions.
            'vat_franchise_base' => [
                'sale_of_goods' => 8_500_000,
                'services_bic' => 3_750_000,
                'services_bnc' => 3_750_000,
            ],
            'vat_franchise_tolerance' => [
                'sale_of_goods' => 9_350_000,
                'services_bic' => 4_125_000,
                'services_bnc' => 4_125_000,
            ],
        ],
    ],

    'contributions' => [
        [
            // Taux d'avant le décret n° 2024-484 du 30 mai 2024. L'entrée
            // suivante portait ces montants au 1er janvier, ce qui surévaluait
            // le premier semestre 2024 pour les BNC et la CIPAV.
            'effective_from' => '2024-01-01',
            'verified' => true,
            'social' => [
                'sale_of_goods' => '12.3',
                'services_bic' => '21.2',
                'services_bnc_ssi' => '21.1',
                'services_bnc_cipav' => '21.2',
            ],
            'training' => [
                'sale_of_goods' => '0.1',
                'services_bic' => '0.3',
                'services_bnc' => '0.2',
            ],
            'income_tax' => [
                'sale_of_goods' => '1.0',
                'services_bic' => '1.7',
                'services_bnc' => '2.2',
            ],
            'acre' => [
                'reduction_percent' => '50',
                'duration_months' => 12,
            ],
        ],
        [
            // Décret n° 2024-484 du 30 mai 2024, en vigueur au 1er juillet :
            // BNC 21,1 → 23,1 et CIPAV 21,2 → 23,2.
            'effective_from' => '2024-07-01',
            'verified' => true,
            // Cotisations sociales. BNC is split because SSI and CIPAV charge
            // differently on identical turnover.
            'social' => [
                'sale_of_goods' => '12.3',
                'services_bic' => '21.2',
                'services_bnc_ssi' => '23.1',
                'services_bnc_cipav' => '23.2',
            ],
            // Contribution à la formation professionnelle. NOTE: the real split
            // is commerçant 0.1 / artisan 0.3 / services and libéral 0.2, and
            // Augias does not record whether a BIC company is a trader or a
            // craftsman — so the BIC services figure here is the one to check
            // first for an artisan.
            'training' => [
                'sale_of_goods' => '0.1',
                'services_bic' => '0.3',
                'services_bnc' => '0.2',
            ],
            // Versement libératoire de l'impôt sur le revenu, when opted into.
            'income_tax' => [
                'sale_of_goods' => '1.0',
                'services_bic' => '1.7',
                'services_bnc' => '2.2',
            ],
            // ACRE: percentage taken off the social rate, and for how long.
            'acre' => [
                'reduction_percent' => '50',
                'duration_months' => 12,
            ],
        ],
        [
            // Palier suivant du même décret du 30 mai 2024.
            'effective_from' => '2025-01-01',
            'verified' => true,
            'social' => [
                'sale_of_goods' => '12.3',
                'services_bic' => '21.2',
                'services_bnc_ssi' => '24.6',
                'services_bnc_cipav' => '23.2',
            ],
            'training' => [
                'sale_of_goods' => '0.1',
                'services_bic' => '0.3',
                'services_bnc' => '0.2',
            ],
            'income_tax' => [
                'sale_of_goods' => '1.0',
                'services_bic' => '1.7',
                'services_bnc' => '2.2',
            ],
            'acre' => [
                'reduction_percent' => '50',
                'duration_months' => 12,
            ],
        ],
        [
            // Palier suivant du même décret du 30 mai 2024.
            'effective_from' => '2026-01-01',
            'verified' => true,
            'social' => [
                'sale_of_goods' => '12.3',
                'services_bic' => '21.2',
                'services_bnc_ssi' => '26.1',
                'services_bnc_cipav' => '23.2',
            ],
            'training' => [
                'sale_of_goods' => '0.1',
                'services_bic' => '0.3',
                'services_bnc' => '0.2',
            ],
            'income_tax' => [
                'sale_of_goods' => '1.0',
                'services_bic' => '1.7',
                'services_bnc' => '2.2',
            ],
            'acre' => [
                'reduction_percent' => '50',
                'duration_months' => 12,
            ],
        ],
        [
            // L'Acre tombe de 50 % à 25 % pour les micro-entreprises créées à
            // compter du 1er juillet 2026 : le taux minoré passe de 50 % à 75 %
            // des taux pleins.
            //
            // Cette entrée est lue par la *date de création*, pas par celle de
            // la période — c'est elle qui fixe les conditions de l'aide, et une
            // entreprise créée en juin 2026 garde ses 50 % pour les douze mois
            // qui suivent. Voir MicroContributionCalculator::applyAcre().
            // Les autres taux sont ceux de l'entrée précédente, inchangés.
            'effective_from' => '2026-07-01',
            'verified' => true,
            'social' => [
                'sale_of_goods' => '12.3',
                'services_bic' => '21.2',
                'services_bnc_ssi' => '26.1',
                'services_bnc_cipav' => '23.2',
            ],
            'training' => [
                'sale_of_goods' => '0.1',
                'services_bic' => '0.3',
                'services_bnc' => '0.2',
            ],
            'income_tax' => [
                'sale_of_goods' => '1.0',
                'services_bic' => '1.7',
                'services_bnc' => '2.2',
            ],
            'acre' => [
                'reduction_percent' => '25',
                'duration_months' => 12,
            ],
        ],
    ],
];
