<?php

namespace App\Enums;

enum PaymentMethod: string
{
    // Legacy/back-office values (pre field-collection ruling).
    case CASH = 'CASH';
    case TRANSFER = 'TRANSFER';
    case POS = 'POS';
    case OTHER = 'OTHER';

    /*
     * Field-collection ruling: the CUSTOMER settles at the PRIMARY
     * (distributor) office, never with the sales employee. These explicit
     * values encode WHERE the money was received; a bare 'CASH' must never be
     * used for a field record because it would imply the employee collected
     * cash personally.
     */
    case BANK_TRANSFER_TO_PRIMARY = 'BANK_TRANSFER_TO_PRIMARY';
    case POS_AT_PRIMARY = 'POS_AT_PRIMARY';
    case CASH_AT_PRIMARY = 'CASH_AT_PRIMARY';

    /** The three explicit field-collection methods (order = UI order). */
    public static function fieldOptions(): array
    {
        return [
            self::BANK_TRANSFER_TO_PRIMARY,
            self::POS_AT_PRIMARY,
            self::CASH_AT_PRIMARY,
        ];
    }

    public function settlesAtPrimary(): bool
    {
        return in_array($this, self::fieldOptions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash (legacy)',
            self::TRANSFER => 'Transfer (legacy)',
            self::POS => 'POS (legacy)',
            self::OTHER => 'Other (legacy)',
            self::BANK_TRANSFER_TO_PRIMARY => 'Bank transfer to Primary',
            self::POS_AT_PRIMARY => 'POS at Primary',
            self::CASH_AT_PRIMARY => 'Cash at Primary office',
        };
    }

    public function evidenceHint(): ?string
    {
        return match ($this) {
            self::BANK_TRANSFER_TO_PRIMARY => 'Verify the transfer with the Primary office, then photograph or save the transfer receipt.',
            self::POS_AT_PRIMARY => 'Photograph the Primary\'s POS receipt.',
            self::CASH_AT_PRIMARY => 'Cash is settled at the Primary office — photograph the Primary\'s receipt. Never collect cash personally.',
            default => null,
        };
    }
}
