<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate">{{ $customer->business_name }}</h1>
                <p class="text-xs text-on-surface-variant">
                    {{ $customer->customer_type->value }}
                    @if ($customer->city) · {{ $customer->city }} @endif
                    @if ($customer->salesRegion) · {{ $customer->salesRegion->description }} @endif
                    @if ($customer->phone_number) · {{ $customer->phone_number }} @endif
                </p>
            </div>
            <a href="{{ route('customers.index') }}" class="m-chip shrink-0">← Customers</a>
        </div>
    </x-slot>

    @php
        $cityState = trim(implode(', ', array_filter([$customer->city, $customer->state])));
        $addressLine = trim(implode(', ', array_filter([$customer->address, $customer->address2])));
        $regionMarket = trim(implode(' · ', array_filter([$customer->salesRegion?->description, $customer->market])));
        $customerDetails = array_filter([
            'Contact person' => $customer->contact_person,
            'Phone' => $customer->phone_number,
            'Email' => $customer->email_address,
            'Address' => $addressLine,
            'City / State' => $cityState,
            'Postal code' => $customer->postal_code,
            'Country' => $customer->country,
            'Region / Market' => $regionMarket,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');
        $hasCoordinates = $customer->gps_latitude !== null && $customer->gps_longitude !== null;
        $mapLink = $hasCoordinates
            ? 'https://www.openstreetmap.org/?mlat='.$customer->gps_latitude.'&mlon='.$customer->gps_longitude.'#map='.((int) config('erp.map.zoom', 16)).'/'.$customer->gps_latitude.'/'.$customer->gps_longitude
            : null;
    @endphp

    <div class="max-w-3xl space-y-3">
        <div class="m-card p-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold">Customer details</h2>
                    <p class="truncate text-[11px] text-on-surface-variant">{{ $customer->customer_id }}</p>
                </div>
                <span class="m-chip shrink-0">{{ $customer->customer_type->value }}</span>
            </div>

            <dl class="mt-2 divide-y divide-outline-variant text-sm">
                @foreach ($customerDetails as $label => $value)
                    <div class="flex items-start justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                        <dt class="shrink-0 text-on-surface-variant">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-2 flex flex-wrap gap-2">
                @if ($hasCoordinates)
                    <a href="#location" class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">Show on map</a>
                    <a href="{{ $mapLink }}" target="_blank" rel="noopener"
                       class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">Open in maps</a>
                @endif
                @if ($customer->phone_number)
                    <a href="tel:{{ $customer->phone_number }}"
                       class="inline-flex h-9 items-center rounded-full bg-surface-variant px-3 text-[11px] font-medium text-on-surface-variant">Call</a>
                @endif
                @can('update', $customer)
                    <a href="{{ route('customers.edit', $customer) }}"
                       class="inline-flex h-9 items-center rounded-full bg-primary-container px-3 text-[11px] font-medium text-on-primary-container">Edit customer master</a>
                @endcan
            </div>
        </div>

        <div class="m-card p-3">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold">Preferred visit</h2>
                    <p class="text-[11px] text-on-surface-variant">{{ $companyId }} company schedule</p>
                </div>
                @if ($isAssignedWithinCompany)
                    <a href="{{ route('fjp.create', ['customer' => $customer->customer_id]) }}"
                       class="text-xs font-medium text-primary">+ Add visit time</a>
                @endif
            </div>

            @forelse ($plan as $entry)
                <div class="mt-2 flex items-center justify-between gap-2 border-t border-outline-variant pt-2 first:border-0">
                    <x-fjp-chip :week="$entry->preferred_week" :day="$entry->preferred_day" />
                    <a href="{{ route('fjp.edit', $entry) }}" class="text-xs font-medium text-primary">Edit</a>
                </div>
            @empty
                <p class="mt-2 text-sm text-on-surface-variant">
                    {{ $isAssignedWithinCompany
                        ? 'No preferred visit time is set for this company.'
                        : 'Assign this customer to an employee in this company before setting preferred visits.' }}
                </p>
            @endforelse
        </div>

        @if ($hasCoordinates)
            <div class="m-card scroll-mt-16 p-3" id="location">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Location</h2>
                    <a href="{{ $mapLink }}" target="_blank" rel="noopener" class="text-[11px] font-medium text-primary">Open in maps</a>
                </div>
                <p class="mt-1 text-[11px] text-on-surface-variant">
                    Registered location: {{ $customer->gps_latitude }}, {{ $customer->gps_longitude }}
                    @if ($addressLine !== '') · {{ $addressLine }} @endif
                </p>
                @php
                    $locationConfig = [
                        'mode' => 'view',
                        'tileUrl' => config('erp.map.tile_url'),
                        'attribution' => config('erp.map.attribution'),
                        'zoom' => (int) config('erp.map.zoom', 16),
                        'lat' => (float) $customer->gps_latitude,
                        'lng' => (float) $customer->gps_longitude,
                    ];
                @endphp
                <div x-data='customerRegistration(@json($locationConfig))' class="mt-2">
                    <div x-ref="map" class="h-52 w-full overflow-hidden rounded-m bg-surface-variant" role="img"
                         aria-label="Customer registered location"></div>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
