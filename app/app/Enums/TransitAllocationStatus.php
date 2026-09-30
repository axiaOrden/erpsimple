<?php

namespace App\Enums;

/**
 * Phase 9 — lifecycle of a transit-stock reservation against a Delivery
 * Item (implementation ruling 3: allocation is REVERSIBLE until Shipment
 * START, which is the irreversible physical boundary). Rows are history,
 * never deleted.
 */
enum TransitAllocationStatus: string
{
    /** Reserved against the transit balance; releasable with the delivery. */
    case ACTIVE = 'ACTIVE';

    /** Delivery released before START: quantity returned to the transit row; row kept for audit. */
    case RELEASED = 'RELEASED';

    /** Shipment START: physically irreversible; delivery release can no longer restore it. */
    case FINALIZED = 'FINALIZED';
}
