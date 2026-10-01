<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proof-of-settlement audit artefact for a payment recorded by a sales
 * employee (the customer settles at the PRIMARY, never with the employee).
 *
 * Evidence NEVER participates in money math — FinanceService remains the only
 * writer of payment/invoice settlement state. GPS coordinates are structured
 * columns; the watermark is presentation only and never authoritative.
 */
class PaymentEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'payment_evidence';

    protected $primaryKey = 'evidence_id';

    protected $fillable = [
        'payment_id',
        'stored_path',
        'original_name',
        'mime_type',
        'byte_size',
        'gps_latitude',
        'gps_longitude',
        'gps_accuracy',
        'captured_at',
        'watermark_text',
        'uploaded_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'captured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }

    public function uploadedByEmployee()
    {
        return $this->belongsTo(EmployeeMaster::class, 'uploaded_by', 'employee_id');
    }
}
