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

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $parameters = $containerConfigurator->parameters();

    // The hosted service sells in Europe: the euro, and the currencies of the
    // other EU and EEA countries, Switzerland and the United Kingdom.
    $parameters->set('env(AUGIAS_CURRENCIES)', 'EUR,CHF,GBP,DKK,SEK,NOK,ISK,PLN,CZK,HUF,RON');
    $parameters->set('env(AUGIAS_LEMON_SQUEEZY_API_KEY)', '');
    $parameters->set('env(AUGIAS_LEMON_SQUEEZY_STORE_ID)', '');
    $parameters->set('env(AUGIAS_LEMON_SQUEEZY_WEBHOOK_SECRET)', '');
    $parameters->set('env(AUGIAS_SAAS_ONBOARDING_COUPON_CODE)', '');
    $parameters->set('env(AUGIAS_SAAS_ONBOARDING_COUPON_PERCENT)', '30');
    $parameters->set('env(AUGIAS_SAAS_TRIAL_BANNER_DAYS)', '7');
    $parameters->set('env(AUGIAS_SAAS_TRIAL_COUPON_DAYS)', '2');
    // The address the hosted service sends from, on a domain it can sign for.
    // A company's own address goes in Reply-To (see PlatformSenderListener).
    $parameters->set('env(AUGIAS_SAAS_MAIL_FROM)', 'augias@herc-si.fr');
    // The name on messages sent outside any company: sign-in codes, password
    // resets, onboarding. A company's messages read "<company> via Augias".
    $parameters->set('env(AUGIAS_SAAS_MAIL_NAME)', 'Augias by HERC SI');
};
