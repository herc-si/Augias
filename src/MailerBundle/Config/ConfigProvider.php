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

namespace Augias\MailerBundle\Config;

use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Augias\SettingsBundle\Form\Type\MailTransportType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            // A placeholder on a domain reserved for examples (RFC 2606): it
            // never delivers, so an installation that has not set its own
            // sender finds out on the first e-mail rather than sending in
            // somebody else's name.
            new Config(
                'email/from_address',
                'no-reply@augias.example',
                null,
                EmailType::class,
                ['trial_restricted' => true]
            ),
            new Config('email/from_name', $data['company_name'] ?? '', null, TextType::class),
            new Config(
                'email/sending_options/provider',
                null,
                null,
                MailTransportType::class,
                ['trial_restricted' => true]
            ),
        ];
    }
}
