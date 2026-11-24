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

namespace Augias\McpBundle\Twig;

use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantTranscriber;
use Twig\Attribute\AsTwigFunction;

/**
 * Whether the layout offers the assistant's side panel: only once a model
 * is configured.
 */
final readonly class AssistantExtension
{
    public function __construct(
        private Assistant $assistant,
        private AssistantTranscriber $transcriber,
    ) {
    }

    #[AsTwigFunction('assistant_available')]
    public function isAvailable(): bool
    {
        return $this->assistant->isAvailable();
    }

    /**
     * Whether the panel offers its microphone.
     */
    #[AsTwigFunction('assistant_voice_available')]
    public function isVoiceAvailable(): bool
    {
        return $this->assistant->isAvailable() && $this->transcriber->isConfigured();
    }
}
