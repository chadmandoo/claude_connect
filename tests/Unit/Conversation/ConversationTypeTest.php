<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation;

use App\Conversation\ConversationType;
use PHPUnit\Framework\TestCase;

class ConversationTypeTest extends TestCase
{
    public function testBrainstormValue(): void
    {
        $this->assertSame('brainstorm', ConversationType::BRAINSTORM->value);
    }

    public function testPlanningValue(): void
    {
        $this->assertSame('planning', ConversationType::PLANNING->value);
    }

    public function testTaskValue(): void
    {
        $this->assertSame('task', ConversationType::TASK->value);
    }

    public function testDiscussionValue(): void
    {
        $this->assertSame('discussion', ConversationType::DISCUSSION->value);
    }

    public function testCheckInValue(): void
    {
        $this->assertSame('check_in', ConversationType::CHECK_IN->value);
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(5, ConversationType::cases());
    }

    public function testFromString(): void
    {
        $this->assertSame(ConversationType::BRAINSTORM, ConversationType::from('brainstorm'));
        $this->assertSame(ConversationType::PLANNING, ConversationType::from('planning'));
        $this->assertSame(ConversationType::TASK, ConversationType::from('task'));
        $this->assertSame(ConversationType::DISCUSSION, ConversationType::from('discussion'));
        $this->assertSame(ConversationType::CHECK_IN, ConversationType::from('check_in'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        ConversationType::from('invalid');
    }
}
