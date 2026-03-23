<?php

declare(strict_types=1);

namespace App\StateMachine;

enum TaskState: string
{
    /**
     * Returns the valid states this state can transition to.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::RUNNING],
            self::RUNNING => [self::COMPLETED, self::FAILED],
            self::COMPLETED => [],
            self::FAILED => [self::PENDING],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED => true,
            default => false,
        };
    }
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
