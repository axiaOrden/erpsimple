<?php

namespace App\Enums;

enum MovementType: string
{
    case STOCK_COUNT_BASELINE = 'STOCK_COUNT_BASELINE';
    case STOCK_COUNT_VARIANCE = 'STOCK_COUNT_VARIANCE';
    case GOODS_RECEIPT = 'GOODS_RECEIPT';
    case GOODS_ISSUE = 'GOODS_ISSUE';
    case VAN_RETURN = 'VAN_RETURN';
    case CUSTOMER_RETURN = 'CUSTOMER_RETURN';
    case DAMAGE = 'DAMAGE';
    case ADJUSTMENT = 'ADJUSTMENT';
}
