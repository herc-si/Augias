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

namespace Augias\CoreBundle\Tests\Entity;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Entity\SupportSettings;
use Augias\CoreBundle\Enum\SupportRequestStatus;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SupportRequestTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-01 10:00:00');
    }

    public function testOnlyWhoeverTookItIsAdmittedAndOnlyUntilItRunsOut(): void
    {
        $request = $this->request(hours: 24);

        self::assertFalse($request->admits('ops@example.test', $this->now), 'Nobody before it is taken.');

        $request->accept('ops@example.test', $this->now);

        self::assertTrue($request->admits('ops@example.test', $this->now));
        self::assertFalse($request->admits('other@example.test', $this->now));
        self::assertTrue($request->admits('ops@example.test', $this->now->modify('+23 hours 59 minutes')));
        self::assertFalse($request->admits('ops@example.test', $this->now->modify('+24 hours')));
    }

    public function testTakenBySomeoneElseTheFirstKeepsIt(): void
    {
        $request = $this->request();
        $request->accept('ops@example.test', $this->now);
        $request->accept('ops@example.test', $this->now);

        $this->expectException(LogicException::class);
        $request->accept('other@example.test', $this->now);
    }

    public function testNobodyClosesARequestWithoutSayingWhatWasDone(): void
    {
        $request = $this->request();
        $request->accept('ops@example.test', $this->now);

        $this->expectException(LogicException::class);
        $request->resolve('ops@example.test', '   ', $this->now);
    }

    public function testRevokedItAdmitsNobody(): void
    {
        $request = $this->request();
        $request->accept('ops@example.test', $this->now);
        $request->revoke('owner@example.test', $this->now);

        self::assertSame(SupportRequestStatus::Revoked, $request->getStatus());
        self::assertFalse($request->admits('ops@example.test', $this->now));
        self::assertFalse($request->isOpen($this->now));

        $this->expectException(LogicException::class);
        $request->accept('ops@example.test', $this->now);
    }

    public function testAnExpiredRequestCannotBeTaken(): void
    {
        $request = $this->request(hours: 1);

        $this->expectException(LogicException::class);
        $request->accept('ops@example.test', $this->now->modify('+2 hours'));
    }

    public function testTheSpansOfferedStopAtTheMaximum(): void
    {
        self::assertSame([1, 24, 72, 168], new SupportSettings()->getDurations());
        self::assertSame([1, 24, 72], new SupportSettings()->setMaxHours(72)->getDurations());
        self::assertSame([1, 24, 48], new SupportSettings()->setMaxHours(48)->getDurations());
        self::assertSame([1], new SupportSettings()->setMaxHours(0)->getDurations());
    }

    private function request(int $hours = 24): SupportRequest
    {
        return new SupportRequest(new Company(), 'owner@example.test', 'Help', $hours, $this->now, $this->now->modify('+' . $hours . ' hours'));
    }
}
