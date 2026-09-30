<?php

namespace App\Enums;

enum TaxType: string
{
    case OUTPUT_TAX = 'OUTPUT_TAX';
    case INPUT_TAX = 'INPUT_TAX';
    case NONE = 'NONE';
}
