<?php

declare(strict_types=1);

namespace App\Project;

enum ProjectState: string
{
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PLANNING => [self::ACTIVE, self::CANCELLED],
            self::ACTIVE => [self::COMPLETED, self::PAUSED, self::STALLED, self::CANCELLED],
            self::PAUSED => [self::ACTIVE, self::CANCELLED],
            self::STALLED => [self::ACTIVE, self::CANCELLED],
            self::COMPLETED => [],
            self::CANCELLED => [],
            self::WORKSPACE => [], // permanent, no transitions
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

    public function isRunnable(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isWorkspace(): bool
    {
        return $this === self::WORKSPACE;
    }
    case PLANNING = 'planning';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case STALLED = 'stalled';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case WORKSPACE = 'workspace';
}
