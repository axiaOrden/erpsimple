<?php

namespace App\Services;

use App\Models\EmployeeMaster;
use App\Models\Payment;
use App\Models\PaymentEvidence;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Proof-of-settlement evidence for a payment recorded by a sales employee.
 *
 * The client resizes/watermarks the image before uploading — that is an
 * OPTIMISATION only. The server independently validates the MIME type, that
 * the payload really is an image, the byte size and the authorization (the
 * caller checks the invoice/debtor relationship) before storing anything.
 *
 * The stored extension is derived from the DETECTED image type, never from the
 * client-supplied filename, so a renamed script can never be stored as an
 * image (or vice versa).
 */
class PaymentEvidenceService
{
    private const TYPE_EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_BMP => 'bmp',
        IMAGETYPE_HEIF => 'heic',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * Validate the upload WITHOUT storing it. Called before any money moves so
     * an unacceptable proof can never leave a half-recorded settlement behind.
     */
    public function assertAcceptable(UploadedFile $file): void
    {
        $maxKb = (int) $this->config->get('erp.payment_evidence.max_kilobytes', 8192);

        if ($file->getSize() > $maxKb * 1024) {
            abort(422, "Proof of payment must be at most {$maxKb} KB.");
        }

        $mime = (string) $file->getMimeType();
        $allowed = (array) $this->config->get('erp.payment_evidence.mimes', []);

        if (! in_array($mime, $allowed, true)) {
            abort(422, 'Proof of payment must be a JPEG, PNG, WebP or HEIC image (got '.$mime.').');
        }

        // A real image decodes; a renamed payload does not.
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! isset($info[2])) {
            abort(422, 'Proof of payment is not a readable image.');
        }

        if (! isset(self::TYPE_EXTENSIONS[$info[2]])) {
            abort(422, 'Unsupported proof-of-payment image format.');
        }
    }

    public function store(Payment $payment, UploadedFile $file, EmployeeMaster $employee, array $meta = []): PaymentEvidence
    {
        $this->assertAcceptable($file);

        $mime = (string) $file->getMimeType();
        $extension = self::TYPE_EXTENSIONS[(int) @getimagesize($file->getRealPath())[2]] ?? 'jpg';

        $disk = (string) $this->config->get('erp.payment_evidence.disk', 'local');
        $directory = trim((string) $this->config->get('erp.payment_evidence.directory', 'payment-evidence'), '/');

        $filename = sprintf(
            'PAY-%d-%s.%s',
            $payment->payment_id,
            Str::lower(Str::random(16)),
            $extension,
        );

        $stored = $file->storeAs($directory, $filename, ['disk' => $disk]);

        return PaymentEvidence::create([
            'payment_id' => $payment->payment_id,
            'stored_path' => $stored,
            'original_name' => $file->getClientOriginalName() !== '' ? mb_substr($file->getClientOriginalName(), 0, 255) : null,
            'mime_type' => $mime,
            'byte_size' => (int) $file->getSize(),
            'gps_latitude' => $meta['gps_latitude'] ?? null,
            'gps_longitude' => $meta['gps_longitude'] ?? null,
            'gps_accuracy' => $meta['gps_accuracy'] ?? null,
            'captured_at' => $meta['captured_at'] ?? null,
            'watermark_text' => isset($meta['watermark_text']) ? mb_substr((string) $meta['watermark_text'], 0, 255) : null,
            'uploaded_by' => $employee->employee_id,
            'created_at' => now(),
        ]);
    }

    public function disk(): string
    {
        return (string) $this->config->get('erp.payment_evidence.disk', 'local');
    }
}
