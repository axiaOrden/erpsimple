<?php

namespace App\Exceptions;

/**
 * Thrown when authoritative confirmation cannot proceed because a business
 * rule is ambiguous (multiple applicable price conditions with no precedence,
 * multiple qualifying deals with no stacking rule). Carries the human-readable
 * conflict list for the UI; the enclosing DB transaction is rolled back so the
 * order remains an untouched DRAFT.
 */
class ConfirmationConflict extends \RuntimeException
{
    /**
     * @param  list<string>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Confirmation blocked by unresolved business conflicts.');
    }
}
