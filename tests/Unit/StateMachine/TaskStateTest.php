<?php

declare(strict_types=1);

namespace Tests\Unit\StateMachine;

use App\StateMachine\TaskState;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Tests for TaskState enum.
 *
 * Covers: string values for all states (pending, running, completed, failed), from()
 * construction, allowed transitions per state, canTransitionTo validation for every
 * state pair, and terminal state detection.
 */
class TaskStateTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('pending', TaskState::PENDING->value);
        $this->assertSame('running', TaskState::RUNNING->value);
        $this->assertSame('completed', TaskState::COMPLETED->value);
        $this->assertSame('failed', TaskState::FAILED->value);
    }

    public function testFromString(): void
    {
        $this->assertSame(TaskState::PENDING, TaskState::from('pending'));
        $this->assertSame(TaskState::RUNNING, TaskState::from('running'));
        $this->assertSame(TaskState::COMPLETED, TaskState::from('completed'));
        $this->assertSame(TaskState::FAILED, TaskState::from('failed'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(ValueError::class);
        TaskState::from('invalid');
    }

    public function testPendingAllowedTransitions(): void
    {
        $allowed = TaskState::PENDING->allowedTransitions();
        $this->assertCount(1, $allowed);
        $this->assertSame(TaskState::RUNNING, $allowed[0]);
    }

    public function testRunningAllowedTransitions(): void
    {
        $allowed = TaskState::RUNNING->allowedTransitions();
        $this->assertCount(2, $allowed);
        $this->assertContains(TaskState::COMPLETED, $allowed);
        $this->assertContains(TaskState::FAILED, $allowed);
    }

    public function testCompletedAllowedTransitions(): void
    {
        $allowed = TaskState::COMPLETED->allowedTransitions();
        $this->assertCount(0, $allowed);
    }

    public function testFailedAllowedTransitions(): void
    {
        $allowed = TaskState::FAILED->allowedTransitions();
        $this->assertCount(1, $allowed);
        $this->assertSame(TaskState::PENDING, $allowed[0]);
    }

    public function testCanTransitionToPendingToRunning(): void
    {
        $this->assertTrue(TaskState::PENDING->canTransitionTo(TaskState::RUNNING));
    }

    public function testCannotTransitionPendingToCompleted(): void
    {
        $this->assertFalse(TaskState::PENDING->canTransitionTo(TaskState::COMPLETED));
    }

    public function testCannotTransitionPendingToFailed(): void
    {
        $this->assertFalse(TaskState::PENDING->canTransitionTo(TaskState::FAILED));
    }

    public function testCannotTransitionPendingToPending(): void
    {
        $this->assertFalse(TaskState::PENDING->canTransitionTo(TaskState::PENDING));
    }

    public function testCanTransitionRunningToCompleted(): void
    {
        $this->assertTrue(TaskState::RUNNING->canTransitionTo(TaskState::COMPLETED));
    }

    public function testCanTransitionRunningToFailed(): void
    {
        $this->assertTrue(TaskState::RUNNING->canTransitionTo(TaskState::FAILED));
    }

    public function testCannotTransitionRunningToPending(): void
    {
        $this->assertFalse(TaskState::RUNNING->canTransitionTo(TaskState::PENDING));
    }

    public function testCannotTransitionCompletedToAnything(): void
    {
        $this->assertFalse(TaskState::COMPLETED->canTransitionTo(TaskState::PENDING));
        $this->assertFalse(TaskState::COMPLETED->canTransitionTo(TaskState::RUNNING));
        $this->assertFalse(TaskState::COMPLETED->canTransitionTo(TaskState::FAILED));
        $this->assertFalse(TaskState::COMPLETED->canTransitionTo(TaskState::COMPLETED));
    }

    public function testCanTransitionFailedToPending(): void
    {
        $this->assertTrue(TaskState::FAILED->canTransitionTo(TaskState::PENDING));
    }

    public function testCannotTransitionFailedToRunning(): void
    {
        $this->assertFalse(TaskState::FAILED->canTransitionTo(TaskState::RUNNING));
    }

    public function testCannotTransitionFailedToCompleted(): void
    {
        $this->assertFalse(TaskState::FAILED->canTransitionTo(TaskState::COMPLETED));
    }

    public function testIsTerminalCompleted(): void
    {
        $this->assertTrue(TaskState::COMPLETED->isTerminal());
    }

    public function testIsTerminalFailed(): void
    {
        $this->assertTrue(TaskState::FAILED->isTerminal());
    }

    public function testIsNotTerminalPending(): void
    {
        $this->assertFalse(TaskState::PENDING->isTerminal());
    }

    public function testIsNotTerminalRunning(): void
    {
        $this->assertFalse(TaskState::RUNNING->isTerminal());
    }
}
