<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fixed Journey Plan rotation anchor
    |--------------------------------------------------------------------------
    |
    | customer_fjp.preferred_week is a position (1–4) in a continuous 4-week
    | field-sales rotation, NOT week-of-month. This anchor is a known MONDAY
    | defined as Rotation Week 1; the cycle runs Week 1→2→3→4→1… continuously
    | across month and year boundaries.
    |
    | Must be a Monday — validated at runtime by FjpRotationService.
    |
    */
    'rotation_anchor' => env('FJP_ROTATION_ANCHOR', '2026-01-05'),

];
