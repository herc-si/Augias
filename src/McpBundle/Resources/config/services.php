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

use Augias\McpBundle\Action\DynamicClientRegistration;
use Augias\McpBundle\AugiasMcpBundle;
use Augias\McpBundle\OAuth\KeyManager;
use Augias\McpBundle\OAuth\PendingAuthorization;
use Augias\McpBundle\OAuth\ServerFactory;
use Augias\McpBundle\OAuth\ServerFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    // The built-in assistant (Assistant\AssistantClient): off until these are set.
    $parameters = $containerConfigurator->parameters();
    $parameters->set('env(AUGIAS_ASSISTANT_BASE_URL)', '');
    $parameters->set('env(AUGIAS_ASSISTANT_API_KEY)', '');
    $parameters->set('env(AUGIAS_ASSISTANT_MODEL)', '');
    // Its microphone (Assistant\AssistantTranscriber): off until these are set too.
    $parameters->set('env(AUGIAS_ASSISTANT_TRANSCRIPTION_URL)', '');
    $parameters->set('env(AUGIAS_ASSISTANT_TRANSCRIPTION_MODEL)', '');

    $services = $containerConfigurator->services();
    $services->defaults()->public();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services
        ->load(AugiasMcpBundle::NAMESPACE . '\\', dirname(__DIR__, 2))
        ->exclude(dirname(__DIR__, 2) . '/{DependencyInjection,Entity,Resources,Tests,AugiasMcpBundle.php}');

    $services
        ->load(AugiasMcpBundle::NAMESPACE . '\\Action\\', dirname(__DIR__, 2) . '/Action')
        ->tag('controller.service_arguments');

    $services->set(KeyManager::class)->arg('$configDir', '%env(AUGIAS_CONFIG_DIR)%')->arg('$encryptionKey', '%env(AUGIAS_APP_SECRET)%');

    $services->set(PendingAuthorization::class);

    $services->alias(ServerFactoryInterface::class, ServerFactory::class);

    $services->set(ServerFactory::class)->arg('$accessTokenTtl', '%env(AUGIAS_MCP_ACCESS_TOKEN_TTL)%')->arg('$refreshTokenTtl', '%env(AUGIAS_MCP_REFRESH_TOKEN_TTL)%')->arg('$authCodeTtl', '%env(AUGIAS_MCP_AUTH_CODE_TTL)%');

    $services->set(DynamicClientRegistration::class)
        ->tag('controller.service_arguments')->arg('$mcpOauthRegisterLimiter', service('limiter.mcp_oauth_register')->nullOnInvalid());
};
