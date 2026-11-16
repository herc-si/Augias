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

namespace Augias\ClientBundle\Action;

use Augias\ClientBundle\Import\ClientCsvReader;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use function implode;

/**
 * The file to fill in: the column titles the import reads, and two lines that
 * show what goes where. Semicolons and a byte order mark, so that Excel opens
 * it in columns and with its accents on a French machine.
 */
final readonly class ImportTemplate
{
    public function __invoke(): Response
    {
        $lines = [
            ClientCsvReader::templateHeader(),
            ['Atelier Dupont SARL', 'Entreprise', '73282932000074', 'FR44732829320', 'contact@atelier-dupont.example', 'Marie', 'Dupont', 'https://atelier-dupont.example', '12 rue des Lilas', '', '69003', 'Lyon', 'France', 'EUR'],
            ['Jean Martin', 'Particulier', '', '', 'jean.martin@example.org', 'Jean', 'Martin', '', '5 place du Marché', 'Bâtiment B', '31000', 'Toulouse', 'France', 'EUR'],
        ];

        $csv = "\xEF\xBB\xBF";

        foreach ($lines as $line) {
            $csv .= implode(';', $line) . "\r\n";
        }

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'clients.csv'),
        ]);
    }
}
