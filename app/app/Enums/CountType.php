<?php

namespace App\Enums;

enum CountType: string
{
    case PRIMARY_OPERATIONAL = 'PRIMARY_OPERATIONAL';
    case SECONDARY_OBSERVATION = 'SECONDARY_OBSERVATION';
    case VAN_CLOSING = 'VAN_CLOSING';
}
