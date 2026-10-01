<?php

namespace Tests\Concerns;

use App\Models\SalesOrderRejectionReason;

/**
 * Controlled sales-order rejection reasons for tests.
 *
 * Rejection is never free text: the service accepts a
 * SalesOrderRejectionReason row, so tests resolve a real, seeded reason by its
 * stable code exactly like the UI does.
 */
trait ProvidesRejectionReasons
{
    protected function reason(string $code = SalesOrderRejectionReason::CODE_CUSTOMER_REQUEST): SalesOrderRejectionReason
    {
        return SalesOrderRejectionReason::byCode($code);
    }

    /** The automation-only reason (used when the application closes free demand). */
    protected function systemReason(): SalesOrderRejectionReason
    {
        return SalesOrderRejectionReason::systemDefault();
    }
}
