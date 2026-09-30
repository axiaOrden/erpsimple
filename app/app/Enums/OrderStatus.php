<?php

namespace App\Enums;

enum OrderStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case OPEN_DELIVERY = 'OPEN_DELIVERY';
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case COMPLETELY_DELIVERED = 'COMPLETELY_DELIVERED';
    case PARTIALLY_REJECTED = 'PARTIALLY_REJECTED';
    case COMPLETELY_REJECTED = 'COMPLETELY_REJECTED';

    /** Confirmed orders can no longer be edited (demand is immutable). */
    public function isConfirmed(): bool
    {
        return $this !== self::DRAFT;
    }
}
