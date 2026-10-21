<?php

namespace App\Enums;

enum ShowtimeStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
}
