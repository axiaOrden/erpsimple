<?php

namespace App\Enums;

/**
 * Phase 9 — who bears responsibility for damaged transit stock.
 * Financial settlement is a deferred future workflow (Phase 8 ruling:
 * damage is never a customer credit).
 */
enum LiabilityParty: string
{
    case NONE = 'NONE';
    case EMPLOYEE = 'EMPLOYEE';
    case DISTRIBUTOR = 'DISTRIBUTOR';
}
