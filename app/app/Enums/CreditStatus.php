<?php

namespace App\Enums;

enum CreditStatus: string
{
    case OPEN = 'OPEN';
    case PARTIALLY_USED = 'PARTIALLY_USED';
    case USED = 'USED';
    case CANCELLED = 'CANCELLED';
}
