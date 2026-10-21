<?php

namespace App\Enums;

enum UserRole: string
{
    case Attendee = 'attendee';
    case Organizer = 'organizer';
    case Admin = 'admin';
}
