<?php

declare(strict_types=1);

namespace Tests\Unit\Project;

use App\Project\ProjectState;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Tests for ProjectState enum.
 *
 * Covers: string values for all states (planning through workspace), from() construction,
 * exhaustive transition validation, terminal state detection, isRunnable checks, and
 * isWorkspace identification.
 */
class ProjectStateTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('planning', ProjectState::PLANNING->value);
        $this->assertSame('active', ProjectState::ACTIVE->value);
        $this->assertSame('paused', ProjectState::PAUSED->value);
        $this->assertSame('stalled', ProjectState::STALLED->value);
        $this->assertSame('completed', ProjectState::COMPLETED->value);
        $this->assertSame('cancelled', ProjectState::CANCELLED->value);
        $this->assertSame('workspace', ProjectState::WORKSPACE->value);
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(7, ProjectState::cases());
    }

    public function testFromString(): void
    {
        $this->assertSame(ProjectState::PLANNING, ProjectState::from('planning'));
        $this->assertSame(ProjectState::ACTIVE, ProjectState::from('active'));
        $this->assertSame(ProjectState::PAUSED, ProjectState::from('paused'));
        $this->assertSame(ProjectState::STALLED, ProjectState::from('stalled'));
        $this->assertSame(ProjectState::COMPLETED, ProjectState::from('completed'));
        $this->assertSame(ProjectState::CANCELLED, ProjectState::from('cancelled'));
        $this->assertSame(ProjectState::WORKSPACE, ProjectState::from('workspace'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(ValueError::class);
        ProjectState::from('invalid');
    }

    // --- canTransitionTo: PLANNING ---

    public function testPlanningCanTransitionToActive(): void
    {
        $this->assertTrue(ProjectState::PLANNING->canTransitionTo(ProjectState::ACTIVE));
    }

    public function testPlanningCanTransitionToCancelled(): void
    {
        $this->assertTrue(ProjectState::PLANNING->canTransitionTo(ProjectState::CANCELLED));
    }

    public function testPlanningCannotTransitionToCompleted(): void
    {
        $this->assertFalse(ProjectState::PLANNING->canTransitionTo(ProjectState::COMPLETED));
    }

    public function testPlanningCannotTransitionToPaused(): void
    {
        $this->assertFalse(ProjectState::PLANNING->canTransitionTo(ProjectState::PAUSED));
    }

    public function testPlanningCannotTransitionToSelf(): void
    {
        $this->assertFalse(ProjectState::PLANNING->canTransitionTo(ProjectState::PLANNING));
    }

    // --- canTransitionTo: ACTIVE ---

    public function testActiveCanTransitionToCompleted(): void
    {
        $this->assertTrue(ProjectState::ACTIVE->canTransitionTo(ProjectState::COMPLETED));
    }

    public function testActiveCanTransitionToPaused(): void
    {
        $this->assertTrue(ProjectState::ACTIVE->canTransitionTo(ProjectState::PAUSED));
    }

    public function testActiveCanTransitionToStalled(): void
    {
        $this->assertTrue(ProjectState::ACTIVE->canTransitionTo(ProjectState::STALLED));
    }

    public function testActiveCanTransitionToCancelled(): void
    {
        $this->assertTrue(ProjectState::ACTIVE->canTransitionTo(ProjectState::CANCELLED));
    }

    public function testActiveCannotTransitionToPlanning(): void
    {
        $this->assertFalse(ProjectState::ACTIVE->canTransitionTo(ProjectState::PLANNING));
    }

    public function testActiveCannotTransitionToWorkspace(): void
    {
        $this->assertFalse(ProjectState::ACTIVE->canTransitionTo(ProjectState::WORKSPACE));
    }

    public function testActiveCannotTransitionToSelf(): void
    {
        $this->assertFalse(ProjectState::ACTIVE->canTransitionTo(ProjectState::ACTIVE));
    }

    // --- canTransitionTo: PAUSED ---

    public function testPausedCanTransitionToActive(): void
    {
        $this->assertTrue(ProjectState::PAUSED->canTransitionTo(ProjectState::ACTIVE));
    }

    public function testPausedCanTransitionToCancelled(): void
    {
        $this->assertTrue(ProjectState::PAUSED->canTransitionTo(ProjectState::CANCELLED));
    }

    public function testPausedCannotTransitionToCompleted(): void
    {
        $this->assertFalse(ProjectState::PAUSED->canTransitionTo(ProjectState::COMPLETED));
    }

    // --- canTransitionTo: STALLED ---

    public function testStalledCanTransitionToActive(): void
    {
        $this->assertTrue(ProjectState::STALLED->canTransitionTo(ProjectState::ACTIVE));
    }

    public function testStalledCanTransitionToCancelled(): void
    {
        $this->assertTrue(ProjectState::STALLED->canTransitionTo(ProjectState::CANCELLED));
    }

    public function testStalledCannotTransitionToCompleted(): void
    {
        $this->assertFalse(ProjectState::STALLED->canTransitionTo(ProjectState::COMPLETED));
    }

    // --- canTransitionTo: COMPLETED ---

    public function testCompletedCannotTransitionToAnything(): void
    {
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::PLANNING));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::ACTIVE));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::PAUSED));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::STALLED));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::CANCELLED));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::WORKSPACE));
        $this->assertFalse(ProjectState::COMPLETED->canTransitionTo(ProjectState::COMPLETED));
    }

    // --- canTransitionTo: CANCELLED ---

    public function testCancelledCannotTransitionToAnything(): void
    {
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::PLANNING));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::ACTIVE));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::PAUSED));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::STALLED));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::COMPLETED));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::WORKSPACE));
        $this->assertFalse(ProjectState::CANCELLED->canTransitionTo(ProjectState::CANCELLED));
    }

    // --- canTransitionTo: WORKSPACE ---

    public function testWorkspaceCannotTransitionToAnything(): void
    {
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::PLANNING));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::ACTIVE));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::PAUSED));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::STALLED));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::COMPLETED));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::CANCELLED));
        $this->assertFalse(ProjectState::WORKSPACE->canTransitionTo(ProjectState::WORKSPACE));
    }

    // --- isTerminal ---

    public function testCompletedIsTerminal(): void
    {
        $this->assertTrue(ProjectState::COMPLETED->isTerminal());
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertTrue(ProjectState::CANCELLED->isTerminal());
    }

    public function testPlanningIsNotTerminal(): void
    {
        $this->assertFalse(ProjectState::PLANNING->isTerminal());
    }

    public function testActiveIsNotTerminal(): void
    {
        $this->assertFalse(ProjectState::ACTIVE->isTerminal());
    }

    public function testPausedIsNotTerminal(): void
    {
        $this->assertFalse(ProjectState::PAUSED->isTerminal());
    }

    public function testStalledIsNotTerminal(): void
    {
        $this->assertFalse(ProjectState::STALLED->isTerminal());
    }

    public function testWorkspaceIsNotTerminal(): void
    {
        $this->assertFalse(ProjectState::WORKSPACE->isTerminal());
    }

    // --- isRunnable ---

    public function testActiveIsRunnable(): void
    {
        $this->assertTrue(ProjectState::ACTIVE->isRunnable());
    }

    public function testPlanningIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::PLANNING->isRunnable());
    }

    public function testPausedIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::PAUSED->isRunnable());
    }

    public function testStalledIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::STALLED->isRunnable());
    }

    public function testCompletedIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::COMPLETED->isRunnable());
    }

    public function testCancelledIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::CANCELLED->isRunnable());
    }

    public function testWorkspaceIsNotRunnable(): void
    {
        $this->assertFalse(ProjectState::WORKSPACE->isRunnable());
    }

    // --- isWorkspace ---

    public function testWorkspaceIsWorkspace(): void
    {
        $this->assertTrue(ProjectState::WORKSPACE->isWorkspace());
    }

    public function testActiveIsNotWorkspace(): void
    {
        $this->assertFalse(ProjectState::ACTIVE->isWorkspace());
    }

    public function testPlanningIsNotWorkspace(): void
    {
        $this->assertFalse(ProjectState::PLANNING->isWorkspace());
    }

    public function testCompletedIsNotWorkspace(): void
    {
        $this->assertFalse(ProjectState::COMPLETED->isWorkspace());
    }

    public function testCancelledIsNotWorkspace(): void
    {
        $this->assertFalse(ProjectState::CANCELLED->isWorkspace());
    }
}
