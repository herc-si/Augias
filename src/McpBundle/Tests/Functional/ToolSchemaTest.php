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

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What an agent is told about each tool's parameters. A PHP array documented
 * as a map ({"name": …}) was announced as a JSON list, so an agent could not
 * pass the fields to change: update_product, update_resource and the filters
 * of list_resource took nothing (19/11/2026).
 */
#[Group('functional')]
final class ToolSchemaTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testAMapParameterIsAnnouncedAsAnObject(): void
    {
        $generator = new SchemaGenerator(new DocBlockParser());
        $checked = 0;

        foreach ($this->toolMethods() as $tool => $method) {
            $properties = $generator->generate($method)['properties'] ?? [];
            $docComment = (string) $method->getDocComment();

            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getType()?->__toString() !== 'array') {
                    continue;
                }

                $name = $parameter->getName();
                $isList = preg_match('/@param\s+list<[^$]*\$' . preg_quote($name, '/') . '\b/', $docComment) === 1;
                $type = $properties[$name]['type'] ?? null;

                self::assertSame(
                    $isList ? 'array' : 'object',
                    $type,
                    sprintf('%s: parameter "%s" is documented as a %s but announced as "%s".', $tool, $name, $isList ? 'list' : 'map', (string) json_encode($type)),
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(5, $checked);
    }

    /**
     * @return iterable<string, ReflectionMethod>
     */
    private function toolMethods(): iterable
    {
        $container = self::getContainer();

        foreach ($container->getServiceIds() as $id) {
            if (! str_contains($id, '\\Mcp\\') || ! class_exists($id)) {
                continue;
            }

            foreach (new ReflectionClass($id)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(McpTool::class);

                if ($attributes !== []) {
                    yield (string) $attributes[0]->newInstance()->name => $method;
                }
            }
        }
    }
}
