<?php

declare(strict_types=1);

namespace App\Item;

enum ItemPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';
}
