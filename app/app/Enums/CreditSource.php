<?php

namespace App\Enums;

enum CreditSource: string
{
    case POD_DAMAGE = 'POD_DAMAGE';
    case RETURN = 'RETURN';
    case MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';
    case OVERPAYMENT = 'OVERPAYMENT';
}
