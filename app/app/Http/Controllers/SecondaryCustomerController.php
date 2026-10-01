<?php

namespace App\Http\Controllers;

use App\Enums\Weekday;
use App\Models\EmployeeMaster;
use App\Services\CustomerRegistrationService;
use App\Services\FjpRotationService;
use App\Services\PhoneNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Field registration of a new SECONDARY customer.
 *
 * The employee MAY CREATE a customer and MAY NOT edit an existing one in this
 * phase. The server — not the form — decides the customer type, the country,
 * the id and the owning assignment; GPS coordinates come from the device
 * capture and are validated, never hand-typed.
 */
class SecondaryCustomerController extends Controller
{
    public function __construct(
        private readonly CustomerRegistrationService $registration,
        private readonly PhoneNumberService $phones,
        private readonly FjpRotationService $rotation,
    ) {}

    public function create(Request $request): View
    {
        $this->employee($request);

        return view('customers.register', [
            'dialCode' => $this->phones->dialCode(),
            'country' => $this->phones->countryName(),
            'rotationWeek' => $this->rotation->rotationWeek(Carbon::today()),
            'todayWeekday' => (int) Carbon::today()->dayOfWeek,
            'weekOptions' => range(1, FjpRotationService::WEEKS_IN_ROTATION),
            // Numeric weekday index → label (0 = Sunday … 6 = Saturday): the same
            // representation customer_fjp stores, so the posted value needs no
            // translation and `W1-Mon` stays a presentation-only construct.
            'weekdayLabels' => collect(Weekday::cases())
                ->mapWithKeys(fn (Weekday $day) => [(string) $day->value => $day->label()])
                ->all(),
            'mapConfig' => [
                'tileUrl' => (string) config('erp.map.tile_url'),
                'attribution' => (string) config('erp.map.attribution'),
                'zoom' => (int) config('erp.map.zoom', 16),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $employee = $this->employee($request);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            // Server-authoritative normalization: any accepted national
            // spelling is fine (0801…, 801…, +234801…), but the number must
            // resolve to a valid local subscriber number for this country.
            'phone' => [
                'required', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $this->phones->canonical($value) === null) {
                        $fail('Enter a valid '.$this->phones->countryName().' phone number, for example '
                            .$this->phones->dialCode().'8012345678.');
                    }
                },
            ],
            // GPS is captured by the device: required, range-checked, and the
            // only coordinate source (no manual lat/lng fields exist).
            'gps_latitude' => ['required', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'sales_region' => ['nullable', 'string', 'max:100'],
            'market' => ['nullable', 'string', 'max:100'],
            'preferred_visits' => ['nullable', 'array', 'max:8'],
            'preferred_visits.*.preferred_week' => ['nullable', 'integer', 'between:1,'.FjpRotationService::WEEKS_IN_ROTATION],
            'preferred_visits.*.preferred_day' => ['required', 'integer', 'between:0,6'],
        ]);

        $result = $this->registration->register($employee, $validated);

        if ($result['duplicate'] !== null) {
            $duplicate = $result['duplicate'];

            return back()
                ->withInput()
                ->withErrors(['phone' => 'Customer already exists with this phone number.'])
                ->with('duplicate_customer', [
                    'customer_id' => $duplicate->customer_id,
                    'business_name' => $duplicate->business_name,
                    'phone' => $this->phones->format($duplicate->phone_canonical ?? $this->phones->canonical($duplicate->phone_number)),
                    'city' => $duplicate->city,
                    'url' => route('visits.customer', $duplicate->customer_id),
                ]);
        }

        $customer = $result['customer'];
        $status = 'Customer '.$customer->business_name.' registered and assigned to you.';

        if ($result['matches_today']) {
            $status .= " Their preferred visit is today — they are on today's FJP. Check in when you arrive.";
        }

        return redirect()->route('visits.customer', $customer->customer_id)->with('status', $status);
    }

    private function employee(Request $request): EmployeeMaster
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 403, 'Only sales employees register customers in the field.');

        return $employee;
    }
}
