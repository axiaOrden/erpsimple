<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPERADMIN = 'SUPERADMIN';
    case COMPANY_ADMIN = 'COMPANY_ADMIN';
    case SALES_EMPLOYEE = 'SALES_EMPLOYEE';
}
