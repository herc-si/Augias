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

namespace Augias\CoreBundle\Company;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use League\Uri\Uri;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use function in_array;
use function rtrim;
use function strtolower;
use function trim;

/**
 * @see \Augias\CoreBundle\Tests\Company\CompanyDomainResolverTest
 */
final class CompanyDomainResolver implements ResetInterface
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @var array<string, ResolvedHost>
     */
    private array $cache = [];

    private bool $defaultHostParsed = false;

    private ?string $defaultHost = null;

    private string $defaultScheme = 'https';

    private int $defaultPort = 443;

    /**
     * @param list<string> $reservedHosts hosts this deployment answers on for
     *                                    its own purposes, which no tenant may
     *                                    claim
     */
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly string $applicationUrl = '',
        private readonly array $reservedHosts = [],
    ) {
    }

    /**
     * Whether a host is one this deployment keeps for itself.
     *
     * Public because two very different places need the same answer and must
     * not disagree: routing, which has to serve such a host rather than refuse
     * it, and validation, which has to stop a tenant claiming it as a custom
     * domain. A second list would drift, and the drift would be silent until
     * someone had already taken the name.
     */
    public function isReserved(string $host): bool
    {
        return in_array(self::normalize($host), $this->normalizedReservedHosts(), true);
    }

    public function resolve(string $host): ResolvedHost
    {
        $host = self::normalize($host);

        if (isset($this->cache[$host])) {
            return $this->cache[$host];
        }

        $this->parseDefaultHost();

        if ($this->defaultHost === null || $host === $this->defaultHost || $this->isLoopbackHost($host)) {
            return $this->cache[$host] = new ResolvedHost(
                HostType::DefaultHost,
                $this->defaultHost ?? $host,
                $this->defaultScheme,
                $this->defaultPort,
            );
        }

        // Ahead of the custom-domain lookup, deliberately. A name this
        // deployment keeps for itself must resolve to itself even if a row
        // somewhere claims it — a reservation added after the fact has to take
        // effect, not lose to whoever got there first.
        if ($this->isReserved($host)) {
            return $this->cache[$host] = new ResolvedHost(
                HostType::Reserved,
                $host,
                $this->defaultScheme,
                $this->defaultPort,
            );
        }

        $company = $host === '' ? null : $this->companyRepository->findOneByCustomDomain($host);

        if ($company instanceof Company) {
            return $this->cache[$host] = new ResolvedHost(
                HostType::CustomDomain,
                $host,
                'https',
                443,
                $company,
            );
        }

        return $this->cache[$host] = new ResolvedHost(
            HostType::Unknown,
            $host,
            $this->defaultScheme,
            $this->defaultPort,
        );
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->defaultHostParsed = false;
        $this->defaultHost = null;
    }

    /**
     * @return list<string>
     */
    private function normalizedReservedHosts(): array
    {
        $hosts = [];

        foreach ($this->reservedHosts as $host) {
            $host = self::normalize($host);

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    private static function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    private function isLoopbackHost(string $host): bool
    {
        return in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true)
            || in_array($host, self::LOOPBACK_HOSTS, true);
    }

    private function parseDefaultHost(): void
    {
        if ($this->defaultHostParsed) {
            return;
        }

        $this->defaultHostParsed = true;

        if ($this->applicationUrl === '') {
            return;
        }

        try {
            $uri = Uri::new($this->applicationUrl);
        } catch (Throwable) {
            return;
        }

        $host = $uri->getHost();

        if ($host === null || $host === '') {
            return;
        }

        $this->defaultHost = self::normalize($host);

        $scheme = $uri->getScheme();
        if ($scheme !== null && $scheme !== '') {
            $this->defaultScheme = strtolower($scheme);
        }

        $port = $uri->getPort();
        $this->defaultPort = $port ?? ($this->defaultScheme === 'http' ? 80 : 443);
    }
}
