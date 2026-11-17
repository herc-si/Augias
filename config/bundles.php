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

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Augias\AccountingBundle\AugiasAccountingBundle;
use Augias\ApiBundle\AugiasApiBundle;
use Augias\BillBundle\AugiasBillBundle;
use Augias\CatalogBundle\AugiasCatalogBundle;
use Augias\ClientBundle\AugiasClientBundle;
use Augias\CoreBundle\AugiasCoreBundle;
use Augias\CronBundle\AugiasCronBundle;
use Augias\DashboardBundle\AugiasDashboardBundle;
use Augias\DataGridBundle\AugiasDataGridBundle;
use Augias\ElectronicInvoicingBundle\AugiasElectronicInvoicingBundle;
use Augias\FormBundle\AugiasFormBundle;
use Augias\InstallBundle\AugiasInstallBundle;
use Augias\InvoiceBundle\AugiasInvoiceBundle;
use Augias\MailerBundle\AugiasMailerBundle;
use Augias\McpBundle\AugiasMcpBundle;
use Augias\MoneyBundle\AugiasMoneyBundle;
use Augias\NotificationBundle\AugiasNotificationBundle;
use Augias\PaymentBundle\AugiasPaymentBundle;
use Augias\QuoteBundle\AugiasQuoteBundle;
use Augias\SettingsBundle\AugiasSettingsBundle;
use Augias\SupplierBundle\AugiasSupplierBundle;
use Augias\TaxBundle\AugiasTaxBundle;
use Augias\UserBundle\AugiasUserBundle;
use BabDev\PagerfantaBundle\BabDevPagerfantaBundle;
use DAMA\DoctrineTestBundle\DAMADoctrineTestBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use KnpU\OAuth2ClientBundle\KnpUOAuth2ClientBundle;
use Liip\TestFixturesBundle\LiipTestFixturesBundle;
use Meilisearch\Bundle\MeilisearchBundle;
use Payum\Bundle\PayumBundle\PayumBundle;
use SolidWorx\Platform\UiBundle\SolidWorxPlatformUiBundle;
use SolidWorx\Toggler\Symfony\TogglerBundle;
use Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\UX\Autocomplete\AutocompleteBundle;
use Symfony\UX\Chartjs\ChartjsBundle;
use Symfony\UX\Dropzone\DropzoneBundle;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\TogglePassword\TogglePasswordBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Symfony\WebpackEncoreBundle\WebpackEncoreBundle;
use SymfonyCasts\Bundle\ResetPassword\SymfonyCastsResetPasswordBundle;
use SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle;
use Zenstruck\Foundry\ZenstruckFoundryBundle;
use Zenstruck\Mailer\Test\ZenstruckMailerTestBundle;

return [
    FrameworkBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    MonologBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    WebpackEncoreBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    PayumBundle::class => ['all' => true],
    StofDoctrineExtensionsBundle::class => ['all' => true],
    ApiPlatformBundle::class => ['all' => true],
    AugiasAccountingBundle::class => ['all' => true],
    AugiasApiBundle::class => ['all' => true],
    AugiasBillBundle::class => ['all' => true],
    AugiasCatalogBundle::class => ['all' => true],
    AugiasClientBundle::class => ['all' => true],
    AugiasCoreBundle::class => ['all' => true],
    AugiasCronBundle::class => ['all' => true],
    AugiasDashboardBundle::class => ['all' => true],
    AugiasDataGridBundle::class => ['all' => true],
    AugiasElectronicInvoicingBundle::class => ['all' => true],
    AugiasFormBundle::class => ['all' => true],
    AugiasInstallBundle::class => ['all' => true],
    AugiasInvoiceBundle::class => ['all' => true],
    AugiasMailerBundle::class => ['all' => true],
    AugiasMcpBundle::class => ['all' => true],
    AugiasMoneyBundle::class => ['all' => true],
    AugiasNotificationBundle::class => ['all' => true],
    AugiasPaymentBundle::class => ['all' => true],
    AugiasQuoteBundle::class => ['all' => true],
    AugiasSettingsBundle::class => ['all' => true],
    AugiasSupplierBundle::class => ['all' => true],
    AugiasTaxBundle::class => ['all' => true],
    AugiasUserBundle::class => ['all' => true],
    DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    LiipTestFixturesBundle::class => ['dev' => true, 'test' => true],
    DebugBundle::class => ['dev' => true],
    MakerBundle::class => ['dev' => true],
    WebProfilerBundle::class => ['dev' => true, 'test' => true],
    TogglerBundle::class => ['all' => true],
    ZenstruckFoundryBundle::class => ['dev' => true, 'test' => true],
    DropzoneBundle::class => ['all' => true],
    StimulusBundle::class => ['all' => true],
    TwigComponentBundle::class => ['all' => true],
    LiveComponentBundle::class => ['all' => true],
    AutocompleteBundle::class => ['all' => true],
    BabDevPagerfantaBundle::class => ['all' => true],
    SymfonyCastsVerifyEmailBundle::class => ['all' => true],
    KnpUOAuth2ClientBundle::class => ['all' => true],
    TogglePasswordBundle::class => ['all' => true],
    SymfonyCastsResetPasswordBundle::class => ['all' => true],
    ZenstruckMailerTestBundle::class => ['dev' => true, 'test' => true],
    SolidWorxPlatformUiBundle::class => ['all' => true],
    ChartjsBundle::class => ['all' => true],
    MeilisearchBundle::class => ['all' => true],
    McpBundle::class => ['all' => true],
    DAMADoctrineTestBundle::class => ['test' => true]
];
