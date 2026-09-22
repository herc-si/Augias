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

use Augias\AppRequirements;
use Augias\CoreBundle\AugiasCoreBundle;
use Augias\CoreBundle\Search\DoctrineEventSubscriberDecorator;
use Monolog\Level;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\SentryBundle\Monolog\LogsHandler;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\UX\StimulusBundle\Helper\StimulusHelper;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $parameters = $containerConfigurator->parameters();

    $parameters->set('env(AUGIAS_DATABASE_URL)', 'sqlite:///%env(AUGIAS_CONFIG_DIR)%/db/augias.db');

    $parameters->set('env(AUGIAS_LOCALE)', 'en');
    $parameters->set('env(AUGIAS_APP_SECRET)', null);
    $parameters->set('env(AUGIAS_INSTALLED)', null);
    $parameters->set('env(AUGIAS_APPLICATION_URL)', '');
    $parameters->set('env(AUGIAS_CUSTOM_DOMAIN_DNS_RECORD)', '');
    // Hosts this deployment answers on for its own purposes. They are served
    // rather than refused as unknown, and no tenant may claim one as a custom
    // domain. Empty by default: a self-hosted install has none.
    $parameters->set('env(AUGIAS_RESERVED_HOSTS)', '');
    // Regular expressions, as framework.trusted_hosts takes them. Empty by
    // default, which trusts the Host header — the Symfony default, and the only
    // safe one when the deployment shape is unknown.
    $parameters->set('env(AUGIAS_TRUSTED_HOSTS)', '');
    $parameters->set('env(AUGIAS_RUNTIME)', null);
    $parameters->set('env(AUGIAS_ALLOW_REGISTRATION)', '0');
    $parameters->set('env(AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID)', null);
    $parameters->set('env(AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET)', null);
    $parameters->set('env(AUGIAS_TURNSTILE_SITE_KEY)', null);
    $parameters->set('env(AUGIAS_TURNSTILE_SECRET_KEY)', null);

    $parameters->set('env(AUGIAS_SENTRY_DSN)', null);
    $parameters->set('env(AUGIAS_SENTRY_RELEASE)', '');
    $parameters->set('env(AUGIAS_SENTRY_SEND_DEFAULT_PII)', '0');
    $parameters->set('env(AUGIAS_SENTRY_TRACES_SAMPLE_RATE)', '0');
    $parameters->set('env(AUGIAS_SENTRY_PROFILES_SAMPLE_RATE)', '0');
    $parameters->set('env(AUGIAS_SENTRY_HTTP_TIMEOUT)', '2');
    $parameters->set('env(AUGIAS_SENTRY_HTTP_CONNECT_TIMEOUT)', '2');
    $parameters->set('env(AUGIAS_MAILER_DSN)', 'null://null');
    $parameters->set('env(AUGIAS_MAILER_SENDER)', 'Augias <no-reply@localhost>');

    // Translation provider DSN (e.g. crowdin://PROJECT_ID:API_TOKEN@default). Empty by
    // default: the provider is only contacted by the translation:push / translation:pull
    // commands, so self-hosted installs need no configuration.
    $parameters->set('env(AUGIAS_TRANSLATION_DSN)', '');
    $parameters->set('env(AUGIAS_MESSENGER_DSN)', 'doctrine://default?queue_name=async');
    $parameters->set('env(AUGIAS_PLATFORM)', null);

    $parameters->set('env(AUGIAS_MEILISEARCH_URL)', '');
    $parameters->set('env(AUGIAS_MEILISEARCH_API_KEY)', '');
    $parameters->set('env(AUGIAS_MEILISEARCH_PREFIX)', 'augias_%env(AUGIAS_ENV)%_');

    $parameters->set('env(AUGIAS_MCP_ACCESS_TOKEN_TTL)', 'P1D');
    $parameters->set('env(AUGIAS_MCP_REFRESH_TOKEN_TTL)', 'P90D');
    $parameters->set('env(AUGIAS_MCP_AUTH_CODE_TTL)', 'PT10M');

    $parameters->set('env(AUGIAS_TELEMETRY_URL)', 'https://insights.solidworx.co');
    $parameters->set('env(AUGIAS_ENABLE_TELEMETRY)', '0'); // default OFF; '1' enables
    $parameters->set('env(AUGIAS_INSTALL_TYPE)', '');       // '' → auto-detect (docker vs manual)

    if ($containerConfigurator->env() === 'test') {
        $parameters->set('env(AUGIAS_CONFIG_DIR)', param('kernel.project_dir') . '/var/cache/test/config');
        $parameters->set('env(AUGIAS_ATTACHMENTS_DIR)', param('kernel.project_dir') . '/var/cache/test/attachments');
    } else {
        $parameters->set('env(AUGIAS_CONFIG_DIR)', param('kernel.project_dir') . '/config/env');
        // Supporting documents, on disk rather than in the database: they are
        // whole PDFs and photographs, and a backup that has to carry them
        // inside every dump is a backup nobody runs. Overridable because the
        // directory that holds six years of accounting records is exactly the
        // kind of thing an install wants on its own volume.
        $parameters->set('env(AUGIAS_ATTACHMENTS_DIR)', param('kernel.project_dir') . '/var/attachments');
    }

    $parameters->set('application_version', AugiasCoreBundle::VERSION);

    $services = $containerConfigurator->services();

    $services
        ->set(PsrLogMessageProcessor::class)
        ->tag('monolog.processor', ['handler' => 'sentry'])
        ->tag('monolog.processor', ['handler' => 'sentry_logs']);

    $services->alias(StimulusHelper::class, 'stimulus.helper');
    $services->set(AppRequirements::class)
        ->autowire(true);

    $services->set(DoctrineEventSubscriberDecorator::class)->arg('$inner', service('.inner'))->arg('$toggle', service(ToggleInterface::class))
        ->decorate('meilisearch.search_indexer_subscriber')
        ->tag('doctrine.event_listener', ['event' => 'postPersist'])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
        ->tag('doctrine.event_listener', ['event' => 'preRemove']);

    $services->set(LogsHandler::class)->arg('$level', Level::Info);
};
