<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1>Register customer</h1>
                <p class="text-xs text-on-surface-variant">New SECONDARY customer — assigned to you automatically</p>
            </div>
            <a href="{{ route('secondary.index') }}" class="m-chip">Cancel</a>
        </div>
    </x-slot>

    @if (session('duplicate_customer'))
        @php $duplicate = session('duplicate_customer'); @endphp
        <div class="m-card mb-4 border border-error/40 p-4">
            <p class="font-semibold text-error">Customer already exists</p>
            <p class="mt-1 text-sm font-medium">{{ $duplicate['business_name'] }}</p>
            <p class="text-sm text-on-surface-variant">
                {{ $duplicate['phone'] }}@if ($duplicate['city']) · {{ $duplicate['city'] }}@endif
            </p>
            <p class="mt-1 text-xs text-on-surface-variant">
                The same phone number cannot be registered twice — open the existing customer instead.
            </p>
            <a href="{{ $duplicate['url'] }}"
               class="mt-3 inline-flex h-11 items-center justify-center rounded-full bg-primary px-5 text-sm font-semibold text-on-primary">
                View customer
            </a>
        </div>
    @endif

    @php
        $initialVisits = old('preferred_visits', [
            ['preferred_week' => $rotationWeek, 'preferred_day' => $todayWeekday],
        ]);

        $mapComponentConfig = [
            'mode' => 'capture',
            'tileUrl' => $mapConfig['tileUrl'],
            'attribution' => $mapConfig['attribution'],
            'zoom' => $mapConfig['zoom'],
            'reverseUrl' => route('geo.reverse'),
            'dialCode' => $dialCode,
            'country' => $country,
            'lat' => old('gps_latitude'),
            'lng' => old('gps_longitude'),
            'accuracy' => old('gps_accuracy'),
            'visits' => $initialVisits,
            'weekOptions' => $weekOptions,
            'weekdayLabels' => $weekdayLabels,
            'rotationWeek' => $rotationWeek,
            'todayWeekday' => $todayWeekday,
            'address' => old('address'),
            'city' => old('city'),
            'state' => old('state'),
            'postalCode' => old('postal_code'),
        ];
    @endphp

    <form method="POST" action="{{ route('secondary.store') }}" class="space-y-4"
          x-data='customerRegistration(@json($mapComponentConfig))'>
        @csrf

        @if ($errors->any())
            <div class="m-card border border-error/40 p-3 text-sm text-error">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Identity --}}
        <section class="m-card p-4 space-y-3">
            <h2 class="text-base font-semibold">Customer</h2>

            <div>
                <label for="business_name" class="block text-xs font-medium text-on-surface-variant">Business / customer name *</label>
                <input id="business_name" name="business_name" type="text" required maxlength="255"
                       value="{{ old('business_name') }}"
                       class="mt-1 w-full rounded-m border-outline-variant bg-surface">
            </div>

            <div>
                <label for="contact_person" class="block text-xs font-medium text-on-surface-variant">Contact person</label>
                <input id="contact_person" name="contact_person" type="text" maxlength="255"
                       value="{{ old('contact_person') }}"
                       class="mt-1 w-full rounded-m border-outline-variant bg-surface">
            </div>

            <div>
                <label for="phone_local" class="block text-xs font-medium text-on-surface-variant">Phone *</label>
                <div class="mt-1 flex items-stretch gap-2">
                    <span class="inline-flex items-center rounded-m bg-surface-variant px-3 text-sm font-medium text-on-surface-variant">
                        {{ $dialCode }}
                    </span>
                    <input id="phone_local" type="tel" inputmode="numeric" autocomplete="tel-national"
                           x-model="phoneLocal" @input="phoneLocal = phoneLocal.replace(/\D+/g, '')"
                           placeholder="8012345678" maxlength="11"
                           class="w-full rounded-m border-outline-variant bg-surface tabular-nums"
                           aria-describedby="phone-help">
                </div>
                <input type="hidden" name="phone" :value="dialCode + phoneLocal">
                <p id="phone-help" class="mt-1 text-[11px] text-on-surface-variant">
                    Enter the local number without the leading zero. Saved canonically as
                    <span x-text="dialCode + phoneLocal"></span> — the same number in any spelling is treated as one customer.
                </p>
            </div>
        </section>

        {{-- GPS + address --}}
        <section class="m-card p-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold">Location</h2>
                <button type="button" @click="acquire(true)"
                        class="inline-flex h-10 items-center justify-center rounded-full bg-secondary-container px-4 text-xs font-semibold text-on-secondary-container">
                    Refresh GPS
                </button>
            </div>

            <div class="rounded-m bg-surface-container-high/60 p-3 text-xs">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-on-surface-variant">Coordinates</span>
                    <span class="font-medium tabular-nums" x-text="coordsLabel()"></span>
                </div>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <span class="text-on-surface-variant">GPS accuracy</span>
                    <span class="font-medium tabular-nums" x-text="accuracyLabel()"></span>
                </div>
                <p class="mt-1 text-[11px] text-on-surface-variant" x-cloak x-show="gpsNote" x-text="gpsNote"></p>
                <p class="mt-1 text-[11px] text-error" x-show="gpsError" x-text="gpsError"></p>
            </div>

            {{-- Coordinates are captured, never typed: hidden inputs only. --}}
            <input type="hidden" name="gps_latitude" :value="lat">
            <input type="hidden" name="gps_longitude" :value="lng">
            <input type="hidden" name="gps_accuracy" :value="accuracy">

            <div x-ref="map" class="h-56 w-full overflow-hidden rounded-m bg-surface-variant" role="img"
                 aria-label="Captured position on the map"></div>

            <p class="text-[11px] text-on-surface-variant">
                Check the marker is where you physically are. Text below may be corrected; the coordinates stay as captured.
            </p>

            <div>
                <label for="address" class="block text-xs font-medium text-on-surface-variant">Address</label>
                <input id="address" name="address" type="text" maxlength="255" x-model="address"
                       class="mt-1 w-full rounded-m border-outline-variant bg-surface">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="city" class="block text-xs font-medium text-on-surface-variant">City</label>
                    <input id="city" name="city" type="text" maxlength="100" x-model="city"
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface">
                </div>
                <div>
                    <label for="state" class="block text-xs font-medium text-on-surface-variant">State</label>
                    <input id="state" name="state" type="text" maxlength="100" x-model="state"
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface">
                </div>
                <div>
                    <label for="postal_code" class="block text-xs font-medium text-on-surface-variant">Postal code</label>
                    <input id="postal_code" name="postal_code" type="text" maxlength="30" x-model="postalCode"
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface">
                </div>
                <div>
                    <label for="country_display" class="block text-xs font-medium text-on-surface-variant">Country</label>
                    <input id="country_display" type="text" value="{{ $country }}" readonly disabled
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface-variant cursor-not-allowed">
                </div>
            </div>

            <p class="text-[11px] text-on-surface-variant" x-show="suggestionNote" x-text="suggestionNote"></p>
        </section>

        {{-- Preferred visit (existing FJP model) --}}
        <section class="m-card p-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold">Preferred visit</h2>
                <button type="button" @click="addVisit()"
                        class="inline-flex h-10 items-center justify-center rounded-full bg-secondary-container px-4 text-xs font-semibold text-on-secondary-container">
                    + Add more
                </button>
            </div>

            <p class="text-[11px] text-on-surface-variant">
                Rotation weeks 1–4 run continuously. “Every week” visits on the chosen day regardless of rotation week.
            </p>

            <template x-for="(visit, index) in visits" :key="index">
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block text-[11px] text-on-surface-variant">Week</label>
                        <select :name="'preferred_visits[' + index + '][preferred_week]'" x-model="visit.preferred_week"
                                class="mt-1 w-full rounded-m border-outline-variant bg-surface text-sm">
                            <option value="">Every week</option>
                            <template x-for="week in weekOptions" :key="week">
                                <option :value="week" x-text="week === rotationWeek ? week + ' — this week' : week"></option>
                            </template>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-[11px] text-on-surface-variant">Day</label>
                        <select :name="'preferred_visits[' + index + '][preferred_day]'" x-model="visit.preferred_day"
                                class="mt-1 w-full rounded-m border-outline-variant bg-surface text-sm">
                            <template x-for="(label, key) in weekdayLabels" :key="key">
                                <option :value="key" x-text="Number(key) === todayWeekday ? label + ' — Today' : label"></option>
                            </template>
                        </select>
                    </div>
                    <button type="button" @click="removeVisit(index)" x-show="visits.length > 1"
                            class="h-11 shrink-0 rounded-full bg-surface-variant px-3 text-xs text-on-surface-variant"
                            aria-label="Remove preferred visit">Remove</button>
                </div>
            </template>
        </section>

        <div class="flex gap-2">
            <a href="{{ route('secondary.index') }}"
               class="inline-flex h-12 flex-1 items-center justify-center rounded-full bg-surface-variant text-sm font-medium text-on-surface-variant">
                Cancel
            </a>
            <button type="submit" :disabled="!hasCoordinates() || submitting"
                    class="inline-flex h-12 flex-[2] items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary disabled:opacity-50">
                <span x-show="!submitting">Register customer</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <p class="text-[11px] text-on-surface-variant">
            Registration creates the customer and its assignment to you. It does NOT check you in —
            record the visit explicitly when you are with the customer.
        </p>
    </form>
</x-app-layout>
