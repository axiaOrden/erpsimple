<?php

namespace App\Enums;

enum ConfirmationStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case PARTIAL = 'PARTIAL';
    case REJECTED = 'REJECTED';
}
