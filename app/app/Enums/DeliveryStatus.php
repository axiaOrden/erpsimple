<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case DRAFT = 'DRAFT';
    case ALLOCATED = 'ALLOCATED';
    case SHIPPED = 'SHIPPED';
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case DELIVERED = 'DELIVERED';
}
