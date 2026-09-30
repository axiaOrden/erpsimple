<?php

namespace App\Enums;

enum PaymentTerm: string
{
    case IMMEDIATE = 'IMMEDIATE';
    case PAY_LATER = 'PAY_LATER';
}
