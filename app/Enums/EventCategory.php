<?php

namespace App\Enums;

enum EventCategory: string
{
    case Film = 'film';
    case Concert = 'concert';
    case Orchestra = 'orchestra';
    case Other = 'other';
}
