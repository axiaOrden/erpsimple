<?php

namespace App\Enums;

enum CustomerType: string
{
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
    case VAN = 'VAN';
    case SHIP_TO = 'SHIP_TO';
}
