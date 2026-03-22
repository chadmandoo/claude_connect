<?php

declare(strict_types=1);

namespace App\Conversation;

enum ConversationState: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ABANDONED = 'abandoned';
    case LEARNED = 'learned';
}
