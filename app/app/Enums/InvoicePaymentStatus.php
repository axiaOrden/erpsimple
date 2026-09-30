<?php

namespace App\Enums;

enum InvoicePaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PARTIALLY_PAID = 'PARTIALLY_PAID';
    case PAID = 'PAID';

    /**
     * Any status other than PAID blocks new orders for the invoice's customer.
     * NOTE: which SO party becomes invoice.customer_id (the debtor) is an
     * unresolved business rule — see docs/ARCHITECTURE.md risk 3. This helper
     * only interprets the status; it does not decide debtor identity.
     */
    public function blocksNewOrders(): bool
    {
        return $this !== self::PAID;
    }
}
