<?php

declare(strict_types=1);

namespace App\Epic;

enum EpicState: string
{
    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::IN_PROGRESS, self::CANCELLED],
            self::IN_PROGRESS => [self::OPEN, self::COMPLETED, self::CANCELLED],
            self::COMPLETED => [],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::CANCELLED => true,
            default => false,
        };
    }
}
