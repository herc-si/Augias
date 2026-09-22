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

namespace Augias\CoreBundle\Tests\Validator\Constraints;

use Augias\CoreBundle\Company\CompanyDomainResolver;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Validator\Constraints\NotApplicationUrlHost;
use Augias\CoreBundle\Validator\Constraints\NotApplicationUrlHostValidator;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<NotApplicationUrlHostValidator>
 */
#[CoversClass(NotApplicationUrlHost::class)]
#[CoversClass(NotApplicationUrlHostValidator::class)]
final class NotApplicationUrlHostValidatorTest extends ConstraintValidatorTestCase
{
    private string $applicationUrl = 'https://app.example.com';

    /**
     * @var list<string>
     */
    private array $reservedHosts = [];

    protected function createValidator(): NotApplicationUrlHostValidator
    {
        return new NotApplicationUrlHostValidator(
            new CompanyDomainResolver(
                M::mock(CompanyRepository::class),
                $this->applicationUrl,
                $this->reservedHosts,
            ),
            $this->applicationUrl,
        );
    }

    /**
     * A tenant must not be able to take a name the deployment answers on for
     * its own purposes — an operator console, a status page. Left claimable,
     * the first person to ask for it owns it, and the deployment finds out when
     * it tries to use its own name.
     */
    public function testAReservedHostIsRefused(): void
    {
        $this->reservedHosts = ['ops.example.com'];
        $this->validator = $this->createValidator();
        $this->validator->initialize($this->context);

        $this->validator->validate('ops.example.com', new NotApplicationUrlHost());

        $this->buildViolation((new NotApplicationUrlHost())->message)->assertRaised();
    }

    /**
     * The reservation is on the name, however it is spelled.
     */
    public function testAReservedHostIsRefusedWhateverTheCase(): void
    {
        $this->reservedHosts = ['ops.example.com'];
        $this->validator = $this->createValidator();
        $this->validator->initialize($this->context);

        $this->validator->validate('OPS.Example.COM.', new NotApplicationUrlHost());

        $this->buildViolation((new NotApplicationUrlHost())->message)->assertRaised();
    }

    public function testAHostThatIsNotReservedStillPasses(): void
    {
        $this->reservedHosts = ['ops.example.com'];
        $this->validator = $this->createValidator();
        $this->validator->initialize($this->context);

        $this->validator->validate('billing.acme.test', new NotApplicationUrlHost());

        $this->assertNoViolation();
    }

    public function testNullValuePasses(): void
    {
        $this->validator->validate(null, new NotApplicationUrlHost());
        $this->assertNoViolation();
    }

    public function testEmptyStringPasses(): void
    {
        $this->validator->validate('', new NotApplicationUrlHost());
        $this->assertNoViolation();
    }

    public function testCustomDomainPasses(): void
    {
        $this->validator->validate('acme.example', new NotApplicationUrlHost());
        $this->assertNoViolation();
    }

    public function testEqualToApplicationHostFails(): void
    {
        $constraint = new NotApplicationUrlHost();

        $this->validator->validate('APP.example.com', $constraint);

        $this->buildViolation($constraint->message)->assertRaised();
    }

    #[DataProvider('provideUrlAndPortPrefixedInputs')]
    public function testUrlAndPortPrefixedInputsFail(string $input): void
    {
        $constraint = new NotApplicationUrlHost();

        $this->validator->validate($input, $constraint);

        $this->buildViolation($constraint->message)->assertRaised();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUrlAndPortPrefixedInputs(): iterable
    {
        yield 'scheme prefix' => ['https://app.example.com'];
        yield 'port suffix' => ['app.example.com:8080'];
        yield 'scheme and path' => ['https://app.example.com/some/path'];
        yield 'trailing dot' => ['app.example.com.'];
    }

    public function testEmptyApplicationUrlSkipsValidation(): void
    {
        $this->applicationUrl = '';
        $this->validator = $this->createValidator();

        $this->validator->validateInContext('app.example.com', new NotApplicationUrlHost(), $this->context);
        $this->assertNoViolation();
    }
}
