<?php

declare(strict_types=1);

namespace Tests\Unit\Item;

use App\Item\ItemPriority;
use PHPUnit\Framework\TestCase;

class ItemPriorityTest extends TestCase
{
    public function testLowValue(): void
    {
        $this->assertSame('low', ItemPriority::LOW->value);
    }

    public function testNormalValue(): void
    {
        $this->assertSame('normal', ItemPriority::NORMAL->value);
    }

    public function testHighValue(): void
    {
        $this->assertSame('high', ItemPriority::HIGH->value);
    }

    public function testUrgentValue(): void
    {
        $this->assertSame('urgent', ItemPriority::URGENT->value);
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(4, ItemPriority::cases());
    }

    public function testFromString(): void
    {
        $this->assertSame(ItemPriority::LOW, ItemPriority::from('low'));
        $this->assertSame(ItemPriority::NORMAL, ItemPriority::from('normal'));
        $this->assertSame(ItemPriority::HIGH, ItemPriority::from('high'));
        $this->assertSame(ItemPriority::URGENT, ItemPriority::from('urgent'));
    }

    public function testFromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        ItemPriority::from('invalid');
    }
}
