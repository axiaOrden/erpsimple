<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case DRAFT = 'DRAFT';
    case READY = 'READY';
    case IN_TRANSIT = 'IN_TRANSIT';
    case COMPLETED = 'COMPLETED';
}
