<?php

namespace App\Enums;

enum CountStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case CANCELLED = 'CANCELLED';
}
