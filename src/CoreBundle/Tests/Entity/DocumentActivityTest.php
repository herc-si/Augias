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
use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Enum\RecordKind;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(DocumentActivity::class)]
final class DocumentActivityTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function visitors(): iterable
    {
        yield 'a browser' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', false];
        yield 'a phone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', false];
        yield 'no browser named' => [null, true];
        yield 'a link preview' => ['Mozilla/5.0 (compatible; Microsoft Office/16.0; Microsoft Outlook Link Preview)', true];
        yield 'a crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true];
        yield 'a script' => ['python-requests/2.32.3', true];
    }

    #[DataProvider('visitors')]
    public function testTellsAPersonFromAProgram(?string $userAgent, bool $automated): void
    {
        $activity = new DocumentActivity(new Company(), RecordKind::Quote, new Ulid(), DocumentActivityType::Viewed, new DateTimeImmutable(), userAgent: $userAgent);

        self::assertSame($automated, $activity->isLikelyAutomated());
    }

    public function testWhatIsDoneOnOurSideIsNeverAutomated(): void
    {
        $activity = new DocumentActivity(new Company(), RecordKind::Quote, new Ulid(), DocumentActivityType::Sent, new DateTimeImmutable());

        self::assertFalse($activity->isLikelyAutomated());
    }
}
