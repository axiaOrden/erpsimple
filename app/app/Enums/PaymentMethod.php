<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'CASH';
    case TRANSFER = 'TRANSFER';
    case POS = 'POS';
    case OTHER = 'OTHER';
}
