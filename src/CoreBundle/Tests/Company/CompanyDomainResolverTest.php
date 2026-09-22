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

namespace Augias\CoreBundle\Tests\Company;

use Augias\CoreBundle\Company\CompanyDomainResolver;
use Augias\CoreBundle\Company\HostType;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompanyDomainResolver::class)]
final class CompanyDomainResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testReturnsDefaultHostWhenApplicationUrlEmpty(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldNotReceive('findOneByCustomDomain');

        $resolver = new CompanyDomainResolver($repository, '');

        $resolved = $resolver->resolve('whatever.example');

        self::assertSame(HostType::DefaultHost, $resolved->type);
    }

    public function testMatchesDefaultHost(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldNotReceive('findOneByCustomDomain');

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com');

        $resolved = $resolver->resolve('app.example.com');

        self::assertTrue($resolved->isDefaultHost());
        self::assertSame('app.example.com', $resolved->host);
        self::assertSame('https', $resolved->scheme);
    }

    /**
     * A reserved host is served rather than refused, and is not a tenant's.
     */
    public function testResolvesAReservedHost(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldNotReceive('findOneByCustomDomain');

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com', ['ops.example.com']);

        $resolved = $resolver->resolve('OPS.Example.com.');

        self::assertTrue($resolved->isReserved());
        self::assertFalse($resolved->isCustomDomain());
        self::assertNull($resolved->company);
    }

    /**
     * The reservation is checked before the custom-domain lookup, so a name
     * reserved after someone already claimed it stops being theirs. A
     * reservation that only bound names nobody had taken yet would be no
     * reservation at all.
     */
    public function testAReservedHostWinsOverACompanyThatClaimedIt(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldNotReceive('findOneByCustomDomain');

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com', ['ops.example.com']);

        self::assertTrue($resolver->resolve('ops.example.com')->isReserved());
    }

    public function testAHostThatIsNotReservedIsUnaffected(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldReceive('findOneByCustomDomain')->with('other.example.com')->andReturnNull();

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com', ['ops.example.com']);

        self::assertSame(HostType::Unknown, $resolver->resolve('other.example.com')->type);
    }

    public function testResolvesCustomDomainCompany(): void
    {
        $company = new Company();
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldReceive('findOneByCustomDomain')
            ->once()
            ->with('acme.example')
            ->andReturn($company);

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com');

        $resolved = $resolver->resolve('Acme.Example.');

        self::assertTrue($resolved->isCustomDomain());
        self::assertSame('acme.example', $resolved->host);
        self::assertSame('https', $resolved->scheme);
        self::assertSame(443, $resolved->port);
        self::assertSame($company, $resolved->company);
    }

    #[DataProvider('provideLoopbackHosts')]
    public function testTreatsLoopbackHostsAsDefault(string $host): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldNotReceive('findOneByCustomDomain');

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com');

        $resolved = $resolver->resolve($host);

        self::assertTrue($resolved->isDefaultHost(), 'Loopback host ' . $host . ' should resolve as DefaultHost');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLoopbackHosts(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield 'localhost with port stripped' => ['LOCALHOST'];
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 loopback bracketed' => ['[::1]'];
    }

    public function testReturnsUnknownWhenHostNotFound(): void
    {
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldReceive('findOneByCustomDomain')
            ->once()
            ->with('rogue.example')
            ->andReturnNull();

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com');

        $resolved = $resolver->resolve('rogue.example');

        self::assertSame(HostType::Unknown, $resolved->type);
    }

    public function testCachesResolutionPerHost(): void
    {
        $company = new Company();
        $repository = M::mock(CompanyRepository::class);
        $repository->shouldReceive('findOneByCustomDomain')
            ->once()
            ->andReturn($company);

        $resolver = new CompanyDomainResolver($repository, 'https://app.example.com');

        $first = $resolver->resolve('acme.example');
        $second = $resolver->resolve('acme.example');

        self::assertSame($first, $second);
    }
}
