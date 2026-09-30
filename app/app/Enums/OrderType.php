<?php

namespace App\Enums;

enum OrderType: string
{
    case STANDARD = 'STANDARD';
    case VAN_ORDER = 'VAN_ORDER';
}
