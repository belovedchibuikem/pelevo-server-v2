<?php

namespace App\Integrations\Ai;

final class LanguageCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}
}
