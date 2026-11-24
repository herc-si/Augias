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

namespace Augias\McpBundle\Assistant;

use Augias\CoreBundle\Entity\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use function array_filter;
use function array_pop;
use function array_slice;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * The conversation with the assistant, kept in the session for one company:
 * the page and the side panel read and write the same one.
 *
 * A question is written down, marked pending, before the model is asked, so
 * a page opened meanwhile shows it waiting rather than lost.
 */
final readonly class AssistantConversation
{
    /** Questions and answers kept for the model to follow the thread. */
    public const int HISTORY = 12;

    /** A question still pending after this long was dropped by its request. */
    public const int PENDING_FOR = 120;

    public function __construct(
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function messages(Company $company): array
    {
        $stored = $this->session()->get($this->key($company), []);
        $messages = is_array($stored) ? ($stored['messages'] ?? $stored) : [];

        /** @var list<array{role: 'user'|'assistant', content: string}> */
        return array_values(array_filter(
            is_array($messages) ? $messages : [],
            static fn (mixed $m): bool => is_array($m) && in_array($m['role'] ?? null, ['user', 'assistant'], true) && is_string($m['content'] ?? null),
        ));
    }

    public function isPending(Company $company): bool
    {
        $stored = $this->session()->get($this->key($company), []);
        $since = is_array($stored) ? ($stored['pending'] ?? null) : null;

        return is_int($since) && $this->clock->now()->getTimestamp() - $since < self::PENDING_FOR;
    }

    /**
     * Writes the question down as waiting for its answer.
     */
    public function ask(Company $company, string $question): void
    {
        $messages = $this->messages($company);
        $messages[] = ['role' => 'user', 'content' => $question];

        $this->store($company, $messages, $this->clock->now()->getTimestamp());
    }

    public function answer(Company $company, string $answer): void
    {
        $messages = $this->messages($company);
        $messages[] = ['role' => 'assistant', 'content' => $answer];

        $this->store($company, $messages, null);
    }

    /**
     * The question got no answer at all: it is taken back.
     */
    public function withdraw(Company $company): void
    {
        $messages = $this->messages($company);

        if (($messages[\count($messages) - 1]['role'] ?? null) === 'user') {
            array_pop($messages);
        }

        $this->store($company, $messages, null);
    }

    public function reset(Company $company): void
    {
        $this->session()->remove($this->key($company));
    }

    /**
     * Writes the session out and lets it go, so that the other pages of
     * the same person are not held while the model thinks; it opens again
     * on the next write.
     */
    public function release(): void
    {
        $this->session()->save();
    }

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     */
    private function store(Company $company, array $messages, ?int $pendingSince): void
    {
        $this->session()->set($this->key($company), [
            'messages' => array_slice($messages, -self::HISTORY),
            'pending' => $pendingSince,
        ]);
    }

    private function key(Company $company): string
    {
        return 'assistant_history_' . $company->getId()->toBase32();
    }

    private function session(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
