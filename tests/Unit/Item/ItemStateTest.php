<?php

declare(strict_types=1);

namespace Tests\Unit\Item;

use App\Item\ItemState;
use PHPUnit\Framework\TestCase;

class ItemStateTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('open', ItemState::OPEN->value);
        $this->assertSame('in_progress', ItemState::IN_PROGRESS->value);
        $this->assertSame('review', ItemState::REVIEW->value);
        $this->assertSame('blocked', ItemState::BLOCKED->value);
        $this->assertSame('done', ItemState::DONE->value);
        $this->assertSame('cancelled', ItemState::CANCELLED->value);
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(6, ItemState::cases());
    }

    public function testFromString(): void
    {
        $this->assertSame(ItemState::OPEN, ItemState::from('open'));
        $this->assertSame(ItemState::IN_PROGRESS, ItemState::from('in_progress'));
        $this->assertSame(ItemState::REVIEW, ItemState::from('review'));
        $this->assertSame(ItemState::BLOCKED, ItemState::from('blocked'));
        $this->assertSame(ItemState::DONE, ItemState::from('done'));
        $this->assertSame(ItemState::CANCELLED, ItemState::from('cancelled'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        ItemState::from('invalid');
    }

    // --- canTransitionTo: OPEN ---

    public function testOpenCanTransitionToInProgress(): void
    {
        $this->assertTrue(ItemState::OPEN->canTransitionTo(ItemState::IN_PROGRESS));
    }

    public function testOpenCanTransitionToDone(): void
    {
        $this->assertTrue(ItemState::OPEN->canTransitionTo(ItemState::DONE));
    }

    public function testOpenCanTransitionToCancelled(): void
    {
        $this->assertTrue(ItemState::OPEN->canTransitionTo(ItemState::CANCELLED));
    }

    public function testOpenCannotTransitionToReview(): void
    {
        $this->assertFalse(ItemState::OPEN->canTransitionTo(ItemState::REVIEW));
    }

    public function testOpenCannotTransitionToBlocked(): void
    {
        $this->assertFalse(ItemState::OPEN->canTransitionTo(ItemState::BLOCKED));
    }

    public function testOpenCannotTransitionToSelf(): void
    {
        $this->assertFalse(ItemState::OPEN->canTransitionTo(ItemState::OPEN));
    }

    // --- canTransitionTo: IN_PROGRESS ---

    public function testInProgressCanTransitionToOpen(): void
    {
        $this->assertTrue(ItemState::IN_PROGRESS->canTransitionTo(ItemState::OPEN));
    }

    public function testInProgressCanTransitionToReview(): void
    {
        $this->assertTrue(ItemState::IN_PROGRESS->canTransitionTo(ItemState::REVIEW));
    }

    public function testInProgressCanTransitionToBlocked(): void
    {
        $this->assertTrue(ItemState::IN_PROGRESS->canTransitionTo(ItemState::BLOCKED));
    }

    public function testInProgressCanTransitionToDone(): void
    {
        $this->assertTrue(ItemState::IN_PROGRESS->canTransitionTo(ItemState::DONE));
    }

    public function testInProgressCanTransitionToCancelled(): void
    {
        $this->assertTrue(ItemState::IN_PROGRESS->canTransitionTo(ItemState::CANCELLED));
    }

    public function testInProgressCannotTransitionToSelf(): void
    {
        $this->assertFalse(ItemState::IN_PROGRESS->canTransitionTo(ItemState::IN_PROGRESS));
    }

    // --- canTransitionTo: REVIEW ---

    public function testReviewCanTransitionToDone(): void
    {
        $this->assertTrue(ItemState::REVIEW->canTransitionTo(ItemState::DONE));
    }

    public function testReviewCanTransitionToInProgress(): void
    {
        $this->assertTrue(ItemState::REVIEW->canTransitionTo(ItemState::IN_PROGRESS));
    }

    public function testReviewCanTransitionToOpen(): void
    {
        $this->assertTrue(ItemState::REVIEW->canTransitionTo(ItemState::OPEN));
    }

    public function testReviewCannotTransitionToBlocked(): void
    {
        $this->assertFalse(ItemState::REVIEW->canTransitionTo(ItemState::BLOCKED));
    }

    public function testReviewCannotTransitionToCancelled(): void
    {
        $this->assertFalse(ItemState::REVIEW->canTransitionTo(ItemState::CANCELLED));
    }

    // --- canTransitionTo: BLOCKED ---

    public function testBlockedCanTransitionToInProgress(): void
    {
        $this->assertTrue(ItemState::BLOCKED->canTransitionTo(ItemState::IN_PROGRESS));
    }

    public function testBlockedCanTransitionToDone(): void
    {
        $this->assertTrue(ItemState::BLOCKED->canTransitionTo(ItemState::DONE));
    }

    public function testBlockedCanTransitionToCancelled(): void
    {
        $this->assertTrue(ItemState::BLOCKED->canTransitionTo(ItemState::CANCELLED));
    }

    public function testBlockedCannotTransitionToOpen(): void
    {
        $this->assertFalse(ItemState::BLOCKED->canTransitionTo(ItemState::OPEN));
    }

    public function testBlockedCannotTransitionToReview(): void
    {
        $this->assertFalse(ItemState::BLOCKED->canTransitionTo(ItemState::REVIEW));
    }

    // --- canTransitionTo: DONE ---

    public function testDoneCanTransitionToOpen(): void
    {
        $this->assertTrue(ItemState::DONE->canTransitionTo(ItemState::OPEN));
    }

    public function testDoneCannotTransitionToInProgress(): void
    {
        $this->assertFalse(ItemState::DONE->canTransitionTo(ItemState::IN_PROGRESS));
    }

    public function testDoneCannotTransitionToReview(): void
    {
        $this->assertFalse(ItemState::DONE->canTransitionTo(ItemState::REVIEW));
    }

    public function testDoneCannotTransitionToCancelled(): void
    {
        $this->assertFalse(ItemState::DONE->canTransitionTo(ItemState::CANCELLED));
    }

    // --- canTransitionTo: CANCELLED ---

    public function testCancelledCanTransitionToOpen(): void
    {
        $this->assertTrue(ItemState::CANCELLED->canTransitionTo(ItemState::OPEN));
    }

    public function testCancelledCannotTransitionToInProgress(): void
    {
        $this->assertFalse(ItemState::CANCELLED->canTransitionTo(ItemState::IN_PROGRESS));
    }

    public function testCancelledCannotTransitionToDone(): void
    {
        $this->assertFalse(ItemState::CANCELLED->canTransitionTo(ItemState::DONE));
    }

    // --- isTerminal ---

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(ItemState::CANCELLED->isTerminal());
    }

    public function testDoneIsNotTerminal(): void
    {
        $this->assertFalse(ItemState::DONE->isTerminal());
    }

    public function testOpenIsNotTerminal(): void
    {
        $this->assertFalse(ItemState::OPEN->isTerminal());
    }

    public function testInProgressIsNotTerminal(): void
    {
        $this->assertFalse(ItemState::IN_PROGRESS->isTerminal());
    }

    public function testReviewIsNotTerminal(): void
    {
        $this->assertFalse(ItemState::REVIEW->isTerminal());
    }

    public function testBlockedIsNotTerminal(): void
    {
        $this->assertFalse(ItemState::BLOCKED->isTerminal());
    }
}
