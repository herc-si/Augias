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

namespace Augias\PaymentBundle\Tests\Templates;

use Augias\PaymentBundle\Entity\Payment;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function preg_match;
use function str_contains;
use function strlen;
use function substr;

/**
 * No template may assume a payment has a method.
 *
 * `Payment::getMethod()` returns null, and rows without one exist — nothing
 * requires a method to record that money arrived, and the demo data alone has
 * twenty-seven. Seven templates dereferenced it anyway, which turned a single
 * such row into a 500 on the invoice page, its PDF, the invoice email and the
 * page a customer opens from their invoice link.
 *
 * Asked of the source rather than by rendering, deliberately. The condition
 * that reaches those lines is narrow — a partly paid invoice with a captured
 * payment — so a rendering test passes for the wrong reason the moment the
 * fixture drifts, which is exactly what happened while this was being
 * written. What is worth pinning is the assumption itself.
 */
#[CoversNothing]
final class PaymentMethodIsNeverAssumedTest extends TestCase
{
    public function testTheGetterIsNullableAndSaysSo(): void
    {
        $returnType = new ReflectionMethod(Payment::class, 'getMethod')->getReturnType();

        self::assertNotNull($returnType);
        self::assertTrue($returnType->allowsNull(), 'A payment without a method is a state the entity allows.');
    }

    /**
     * @param list<string> $directories
     *
     * @return iterable<SplFileInfo>
     */
    private function twigFilesIn(array $directories): iterable
    {
        foreach ($directories as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'twig') {
                    yield $file;
                }
            }
        }
    }

    public function testNoTemplateReadsThroughAMethodThatMayBeMissing(): void
    {
        $source = dirname(__DIR__, 3);
        $offenders = [];

        // The two bundles whose `payment` is this entity. BillBundle has a
        // `payment` of its own whose method is an enum and cannot be missing,
        // and a scan that could not tell them apart would either miss the bug
        // or cry wolf about a template that is fine.
        $directories = [$source . '/InvoiceBundle', $source . '/PaymentBundle'];

        /** @var SplFileInfo $file */
        foreach ($this->twigFilesIn($directories) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            // Every `<something>.method.<something>` that is not guarded on
            // the same line by a test of the method itself.
            // A dereference, not a translation key: `payment.method.name`
            // reads through the object, `'payment.method.label'|trans` does
            // not, and only one of the two can be null.
            $lines = explode("\n", $contents);

            foreach ($lines as $number => $line) {
                if (preg_match('/(?<![\'"])\bpayment\.method\.\w+/', $line) !== 1) {
                    continue;
                }

                // The guard is allowed to be on the line above: an `{% if %}`
                // wrapping the line that reads through the method is the same
                // promise, made one line earlier.
                $line .= $lines[$number - 1] ?? '';
                // Three ways to read it safely: a ternary on the method, a
                // condition before it, or Twig's `??`, which swallows the
                // access on a null left-hand side rather than throwing.
                if (str_contains($line, 'payment.method ?')
                    || str_contains($line, 'payment.method and')
                    || str_contains($line, '??')
                ) {
                    continue;
                }

                $offenders[] = substr($file->getPathname(), strlen($source) + 1) . ':' . ($number + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A payment may have no method; read it through a guard:\n" . implode("\n", $offenders),
        );
    }
}
