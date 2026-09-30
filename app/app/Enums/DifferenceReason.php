<?php

namespace App\Enums;

enum DifferenceReason: string
{
    case NONE = 'NONE';
    case EMPLOYEE_DAMAGE = 'EMPLOYEE_DAMAGE';
    case DISTRIBUTOR_DAMAGE = 'DISTRIBUTOR_DAMAGE';
    case CUSTOMER_REJECTED = 'CUSTOMER_REJECTED';
    case SHORT_DELIVERY = 'SHORT_DELIVERY';
    case RETURNED = 'RETURNED';
    case OTHER = 'OTHER';
}
