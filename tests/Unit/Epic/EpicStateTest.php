<?php

declare(strict_types=1);

namespace Tests\Unit\Epic;

use App\Epic\EpicState;
use PHPUnit\Framework\TestCase;

class EpicStateTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('open', EpicState::OPEN->value);
        $this->assertSame('in_progress', EpicState::IN_PROGRESS->value);
        $this->assertSame('completed', EpicState::COMPLETED->value);
        $this->assertSame('cancelled', EpicState::CANCELLED->value);
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(4, EpicState::cases());
    }

    public function testFromString(): void
    {
        $this->assertSame(EpicState::OPEN, EpicState::from('open'));
        $this->assertSame(EpicState::IN_PROGRESS, EpicState::from('in_progress'));
        $this->assertSame(EpicState::COMPLETED, EpicState::from('completed'));
        $this->assertSame(EpicState::CANCELLED, EpicState::from('cancelled'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        EpicState::from('invalid');
    }

    // --- canTransitionTo ---

    public function testOpenCanTransitionToInProgress(): void
    {
        $this->assertTrue(EpicState::OPEN->canTransitionTo(EpicState::IN_PROGRESS));
    }

    public function testOpenCanTransitionToCancelled(): void
    {
        $this->assertTrue(EpicState::OPEN->canTransitionTo(EpicState::CANCELLED));
    }

    public function testOpenCannotTransitionToCompleted(): void
    {
        $this->assertFalse(EpicState::OPEN->canTransitionTo(EpicState::COMPLETED));
    }

    public function testOpenCannotTransitionToSelf(): void
    {
        $this->assertFalse(EpicState::OPEN->canTransitionTo(EpicState::OPEN));
    }

    public function testInProgressCanTransitionToOpen(): void
    {
        $this->assertTrue(EpicState::IN_PROGRESS->canTransitionTo(EpicState::OPEN));
    }

    public function testInProgressCanTransitionToCompleted(): void
    {
        $this->assertTrue(EpicState::IN_PROGRESS->canTransitionTo(EpicState::COMPLETED));
    }

    public function testInProgressCanTransitionToCancelled(): void
    {
        $this->assertTrue(EpicState::IN_PROGRESS->canTransitionTo(EpicState::CANCELLED));
    }

    public function testInProgressCannotTransitionToSelf(): void
    {
        $this->assertFalse(EpicState::IN_PROGRESS->canTransitionTo(EpicState::IN_PROGRESS));
    }

    public function testCompletedCannotTransitionToAnything(): void
    {
        $this->assertFalse(EpicState::COMPLETED->canTransitionTo(EpicState::OPEN));
        $this->assertFalse(EpicState::COMPLETED->canTransitionTo(EpicState::IN_PROGRESS));
        $this->assertFalse(EpicState::COMPLETED->canTransitionTo(EpicState::CANCELLED));
        $this->assertFalse(EpicState::COMPLETED->canTransitionTo(EpicState::COMPLETED));
    }

    public function testCancelledCannotTransitionToAnything(): void
    {
        $this->assertFalse(EpicState::CANCELLED->canTransitionTo(EpicState::OPEN));
        $this->assertFalse(EpicState::CANCELLED->canTransitionTo(EpicState::IN_PROGRESS));
        $this->assertFalse(EpicState::CANCELLED->canTransitionTo(EpicState::COMPLETED));
        $this->assertFalse(EpicState::CANCELLED->canTransitionTo(EpicState::CANCELLED));
    }

    // --- isTerminal ---

    public function testCompletedIsTerminal(): void
    {
        $this->assertTrue(EpicState::COMPLETED->isTerminal());
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(EpicState::CANCELLED->isTerminal());
    }

    public function testOpenIsNotTerminal(): void
    {
        $this->assertFalse(EpicState::OPEN->isTerminal());
    }

    public function testInProgressIsNotTerminal(): void
    {
        $this->assertFalse(EpicState::IN_PROGRESS->isTerminal());
    }
}
