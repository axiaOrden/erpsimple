<?php

namespace App\Enums;

/**
 * Phase 9 — physical disposition of a POD difference, captured at POD.
 * difference_reason says WHY; disposition says WHERE the goods physically
 * are. Transit state derives from the (reason × disposition) pair — never
 * from reason alone (REV 2/3 core ruling).
 */
enum DifferenceDisposition: string
{
    /** Physically with the delivery employee, intact and usable. */
    case WITH_EMPLOYEE = 'WITH_EMPLOYEE';

    /** Physically with the delivery employee, damaged. */
    case DAMAGED = 'DAMAGED';

    /** Claimed handed back at the source (verified later by an explicit source receipt). */
    case AT_SOURCE = 'AT_SOURCE';

    /** Physical whereabouts not established (shortage / unclear). */
    case UNKNOWN = 'UNKNOWN';
}
