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

namespace Augias\McpBundle\Tests\Functional;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\McpSecurityContext;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Doctrine\Persistence\ManagerRegistry;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(McpScopeGuard::class)]
#[CoversClass(McpSecurityContext::class)]
#[CoversClass(McpScope::class)]
final class ScopeGuardTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function testReadScopeSatisfiesReadRequirement(): void
    {
        $this->buildGuard(['mcp:read'])->require(McpScope::Read);
    }

    #[DoesNotPerformAssertions]
    public function testWriteScopeImpliesRead(): void
    {
        $this->buildGuard(['mcp:write'])->require(McpScope::Read);
    }

    #[DoesNotPerformAssertions]
    public function testWriteScopeSatisfiesWriteRequirement(): void
    {
        $this->buildGuard(['mcp:write'])->require(McpScope::Write);
    }

    public function testReadOnlyTokenCannotWrite(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('mcp:write');

        $this->buildGuard(['mcp:read'])->require(McpScope::Write);
    }

    /**
     * A scope is what the person let the assistant do; their role still
     * bounds it. An accountant's assistant cannot write, whatever it was
     * granted.
     */
    public function testTheRoleBoundsWhatAScopeAllows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('Your role');

        $this->buildGuard(['mcp:write'], granted: false)->require(McpScope::Write);
    }

    public function testNoScopesDeniesAccess(): void
    {
        $this->expectException(ToolCallException::class);

        $this->buildGuard([])->require(McpScope::Read);
    }

    /**
     * @param list<string> $scopes
     */
    private function buildGuard(array $scopes, bool $granted = true): McpScopeGuard
    {
        $request = new Request();
        $request->attributes->set(McpOAuthAuthenticator::ATTR_SCOPES, $scopes);

        $stack = new RequestStack([$request]);

        $selector = new CompanySelector($this->createStub(ManagerRegistry::class));

        return new McpScopeGuard(new McpSecurityContext($stack, $selector), $this->authorization($granted));
    }

    private function authorization(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }
}
