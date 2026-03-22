<?php

declare(strict_types=1);

namespace App\Item;

enum ItemState: string
{
    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case REVIEW = 'review';
    case BLOCKED = 'blocked';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::IN_PROGRESS, self::DONE, self::CANCELLED],
            self::IN_PROGRESS => [self::OPEN, self::REVIEW, self::BLOCKED, self::DONE, self::CANCELLED],
            self::REVIEW => [self::DONE, self::IN_PROGRESS, self::OPEN],
            self::BLOCKED => [self::IN_PROGRESS, self::DONE, self::CANCELLED],
            self::DONE => [self::OPEN],
            self::CANCELLED => [self::OPEN],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::CANCELLED;
    }
}
