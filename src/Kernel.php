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

namespace Augias;

use Augias\CoreBundle\Doctrine\Type\ArrayType;
use Augias\CoreBundle\Doctrine\Type\JsonArrayType;
use Augias\CoreBundle\Doctrine\Type\ObjectType;
use Augias\SaasBundle\AugiasSaasBundle;
use BadMethodCallException;
use Doctrine\DBAL\Types\Type;
use Override;
use SolidWorx\Platform\PlatformBundle\Kernel as BaseKernel;
use SolidWorx\Platform\SaasBundle\SolidWorxPlatformSaasBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use function class_exists;
use function preg_replace;

class Kernel extends BaseKernel
{
    public function __construct(
        private readonly AppMode $mode,
        string $environment,
        bool $debug
    ) {
        parent::__construct($environment, $debug);
    }

    #[Override]
    public function boot(): void
    {
        if ($this->debug) {
            $fs = new Filesystem();
            $appModePath = $this->getCacheDir() . '/' . $this->getContainerClass() . '.app_mode';
            if (! $fs->exists($appModePath)) {
                $fs->dumpFile($appModePath, $this->mode->value);
            }

            if ($fs->readFile($appModePath) !== $this->mode->value) {
                $fs->touch($this->getConfigDir() . '/bundles.php');
                $fs->dumpFile($appModePath, $this->mode->value);
            }
        }

        parent::boot();

        if (! Type::hasType('json_array')) {
            // Only here for BC to ensure migrations work. Remove in next minor release.
            Type::addType('json_array', JsonArrayType::class);
        }

        if (! Type::hasType(ArrayType::NAME)) {
            // BC for the "array" type removed in DBAL 4 (used by historical migrations and entities).
            Type::addType(ArrayType::NAME, ArrayType::class);
        }

        if (! Type::hasType(ObjectType::NAME)) {
            // BC for the "object" type removed in DBAL 4 (used by historical migrations and Payum's Token mapping).
            Type::addType(ObjectType::NAME, ObjectType::class);
        }
    }

    #[Override]
    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * The operator console that HERC SI runs against its own tenants.
     *
     * It is a private Composer package, not part of Augias: it manages a
     * hosted business rather than the product, and nobody self-hosting has
     * any use for it. But it has to live *inside* this application rather
     * than beside it, because its whole job is to read across tenants, and
     * the one thing that makes that safe — the `company` Doctrine filter —
     * is defined and tested here. A separate application on the same schema
     * would put the most dangerous query in the codebase furthest from its
     * guard.
     *
     * So the seam is a name, not a dependency. The class is referenced as a
     * string and only instantiated when the package is installed, which
     * means `composer.json` needs no entry a self-hosted install could not
     * resolve, and this repository stays buildable without it.
     *
     * The class is deliberately *not* called AugiasAdminBundle, which is what
     * its namespace would suggest. Symfony Flex derives candidate bundle
     * classes from a package's PSR-4 namespace and, on `composer require`,
     * writes whatever it finds into config/bundles.php — this repository's
     * file, with `['all' => true]`. Committed, that line would break every
     * self-hosted install on a class it does not have. A name Flex cannot
     * derive keeps the registration here, where the mode is checked.
     *
     * @see \Augias\CoreBundle\Tests\KernelOperatorConsoleSeamTest
     */
    public const string OPERATOR_CONSOLE_BUNDLE = 'HercSi\\AugiasAdmin\\AugiasOperatorConsoleBundle';

    #[Override]
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        if ($this->mode === AppMode::SAAS) {
            yield new SolidWorxPlatformSaasBundle();
            yield new AugiasSaasBundle();

            if (class_exists(self::OPERATOR_CONSOLE_BUNDLE)) {
                $bundle = self::OPERATOR_CONSOLE_BUNDLE;

                yield new $bundle();
            }
        }
    }

    #[Override]
    protected function prepareContainer(ContainerBuilder $container): void
    {
        parent::prepareContainer($container);

        $container->set(AppMode::class, $this->mode);
        $container->setParameter('app_mode', $this->mode->value);
    }

    /**
     * The default configureContainer() became private in Symfony 8 (it lives in the
     * DependencyInjection component's KernelTrait now), so the default imports are
     * replicated here instead of calling the parent method.
     */
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());

        $container->import($configDir . '/{packages}/*.{php,yaml}');
        $container->import($configDir . '/{packages}/' . $this->environment . '/*.{php,yaml}');
        $container->import($configDir . '/{services}.php');
        $container->import($configDir . '/{services}_' . $this->environment . '.php');

        if ('staging' === $this->environment) {
            // Staging should mirror production, so load all prod config
            $container->import($configDir . '/{packages}/prod/*.{php,yaml}');
            $container->import($configDir . '/{services}_prod.php');
        }

        if ($this->mode === AppMode::SAAS) {
            $container->import($configDir . '/{packages}/saas/*.{php,yaml}');
        }
    }

    #[Override]
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);

        if ($this->mode === AppMode::SAAS) {
            $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());
            $routes->import($configDir . '/{routes}/saas/*.{php,yaml}');

            // A bundle cannot register its own routes, so the console's are
            // imported from its package once it is registered.
            if (class_exists(self::OPERATOR_CONSOLE_BUNDLE)) {
                $routes->import('@AugiasOperatorConsoleBundle/config/routes.php');
            }
        }
    }

    private function getConfigDir(): string
    {
        return $this->getProjectDir() . '/config';
    }

    #[Override]
    public function __serialize(): array
    {
        return [
            'mode' => $this->mode,
            'environment' => $this->environment,
            'debug' => $this->debug,
        ];
    }

    /**
     * @param array<string, AppMode|string|bool|null> $data
     */
    #[Override]
    public function __unserialize(array $data): void
    {
        // environment/debug keep their "\0*\0" fallbacks: they are protected properties
        // inherited from Symfony's Kernel, so legacy payloads mangle them that way. $mode
        // is private to this class and never existed before it was introduced, so there is
        // no legacy spelling to fall back to - an absent key is simply unusable.
        $environment = $data['environment'] ?? $data["\0*\0environment"];
        $debug = $data['debug'] ?? $data["\0*\0debug"];
        $mode = $data['mode'] ?? null;

        if (\is_object($environment) || \is_object($debug) || ! $mode instanceof AppMode) {
            throw new BadMethodCallException('Cannot unserialize ' . self::class);
        }

        $this->__construct($mode, $environment, $debug);
    }
}
