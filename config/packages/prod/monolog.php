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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Augias\CoreBundle\Watch\ErrorJournalHandler;

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'error',
                'handler' => 'nested',
                'buffer_size' => 50,
                'excluded_http_codes' => [404, 405],
            ],
            'nested' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'debug',
                'formatter' => 'monolog.formatter.json',
            ],
            // Errors into the journal the operator console reads (error_records):
            // what an error-tracking service would receive, kept on this server.
            'error_journal' => [
                'type' => 'service',
                'id' => ErrorJournalHandler::class,
            ],
            'console' => [
                'type' => 'console',
                'process_psr_3_messages' => false,
                'channels' => [
                    'elements' => ['!event', '!doctrine'],
                ],
            ],
        ],
    ],
]);
