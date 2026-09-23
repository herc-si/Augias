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

use Augias\AccountingBundle\AugiasAccountingBundle;
use Augias\AccountingBundle\Config\AccountingConfigProvider;
use Augias\AccountingBundle\DependencyInjection\AugiasAccountingExtension;
use Augias\AccountingBundle\Regime\Fr\FrenchRateTable;
use Augias\DashboardBundle\Attention\AttentionSourceInterface;
use Augias\SettingsBundle\Config\ProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services
        ->defaults()
        ->autoconfigure()
        ->autowire()
        ->private()
    ;

    // `instanceof` only applies to services loaded by this configurator, so the
    // tag DashboardBundle declares for its own has to be repeated here — the
    // same way SaasBundle repeats it for its checklist item.
    $services
        ->instanceof(AttentionSourceInterface::class)
        ->tag('dashboard.attention_source');

    $services
        ->load(AugiasAccountingBundle::NAMESPACE . '\\', dirname(__DIR__, 3))
        // Enum, Exception and Model hold plain value types, not services. They
        // are excluded rather than left to be pruned because autowiring runs
        // before unused definitions are removed, and a value object taking a
        // BigInteger would fail to resolve at compile time.
        ->exclude(dirname(__DIR__, 3) . '/{DependencyInjection,Entity,Enum,Exception,Model,Resources,Tests,Test}');

    $services
        ->load(AugiasAccountingBundle::NAMESPACE . '\\Action\\', dirname(__DIR__, 3) . '/Action')
        ->tag('controller.service_arguments');

    // The rate file path is a plain string and cannot be autowired.
    $services
        ->set(FrenchRateTable::class)
        ->arg('$ratesFile', param(AugiasAccountingExtension::RATES_FILE_PARAMETER));

    // Settings are stored, and then read back, in the order their providers ran
    // — and the settings screen turns the first one into the landing tab. Left
    // to autoconfiguration this bundle would sort first (it is alphabetically
    // ahead of every other Augias bundle) and quietly demote "Company" from
    // being the tab users land on. Autoconfiguration is switched off for this
    // one service so the tag can carry an explicit low priority instead.
    $services
        ->set(AccountingConfigProvider::class)
        ->autoconfigure(false)
        ->tag(ProviderInterface::class, ['priority' => -100]);
};
