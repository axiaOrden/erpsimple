<?php

namespace App\Enums;

enum PriceOverrideReason: string
{
    case DEFAULT = 'DEFAULT';
    case DISTRIBUTOR_OWN_PRICE = 'DISTRIBUTOR_OWN_PRICE';
    case SPECIAL_PRICE = 'SPECIAL_PRICE';

    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => 'Default',
            self::DISTRIBUTOR_OWN_PRICE => 'Distributor own price',
            self::SPECIAL_PRICE => 'Special price',
        };
    }
}
