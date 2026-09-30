<?php

namespace App\Enums;

/**
 * Phase 9 — physical/accounting state of issued-but-unaccepted stock
 * (docs/TRANSIT_STOCK_CORRECTION.md REV 3).
 */
enum TransitStatus: string
{
    /** Intact, with the holding employee, reusable for valid SO demand. */
    case REUSABLE = 'REUSABLE';

    /** Not reusable; liability captured (EMPLOYEE / DISTRIBUTOR), settlement deferred. */
    case DAMAGED = 'DAMAGED';

    /** Whereabouts/condition unestablished (SHORT_DELIVERY / OTHER / refused-not-seen) — investigation state, never auto-stock. */
    case DISCREPANCY = 'DISCREPANCY';

    /** Driver/employee claims the goods were handed back at the source; awaiting source-side verification. */
    case PENDING_SOURCE_RECEIPT = 'PENDING_SOURCE_RECEIPT';

    /** Consumed into another Delivery after Shipment START (physically irreversible). */
    case REALLOCATED = 'REALLOCATED';

    /** Verified back at the source: VAN_RETURN movement written, source availability restored. */
    case RETURNED = 'RETURNED';

    /** Confirmed lost/short — liability deferred, row kept. */
    case LOSS = 'LOSS';

    /** Damaged stock closed out (disposal/settlement) by an admin. */
    case WRITTEN_OFF = 'WRITTEN_OFF';

    public function isTerminal(): bool
    {
        return in_array($this, [self::REALLOCATED, self::RETURNED, self::LOSS, self::WRITTEN_OFF], true);
    }
}
