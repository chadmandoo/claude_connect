<?php

declare(strict_types=1);

namespace App\Conversation;

enum ConversationType: string
{
    case BRAINSTORM = 'brainstorm';
    case PLANNING = 'planning';
    case TASK = 'task';
    case DISCUSSION = 'discussion';
    case CHECK_IN = 'check_in';
}
