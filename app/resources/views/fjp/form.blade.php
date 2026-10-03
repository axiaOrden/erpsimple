<x-app-layout>
    <x-slot name="header">
        <h1>{{ $plan->exists ? 'Edit journey plan' : 'New journey plan' }}</h1>
        <p class="text-sm text-on-surface-variant">Company: {{ $companyId }} · customer preference shared by this company's assigned employees</p>
    </x-slot>

    @php
        $initialVisits = old('preferred_visits', [[
            'preferred_week' => $plan->preferred_week,
            'preferred_day' => $plan->preferred_day ?? 1,
        ]]);
        $weekdayLabels = collect($days)->mapWithKeys(fn ($day) => [(string) $day => \App\Enums\Weekday::from($day)->label()]);
    @endphp

    <form method="POST"
          action="{{ $plan->exists ? route('fjp.update', $plan) : route('fjp.store', request('company') ? ['company' => request('company')] : []) }}"
          class="max-w-3xl space-y-5"
          x-data="{
              visits: {{ Js::from($initialVisits) }},
              weekdayLabels: {{ Js::from($weekdayLabels) }},
              addVisit() { if (this.visits.length < 8) this.visits.push({ preferred_week: '', preferred_day: '1' }); },
              removeVisit(index) { if (this.visits.length > 1) this.visits.splice(index, 1); }
          }">
        @csrf
        @if ($plan->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            <div>
                <x-input-label for="customer_id" value="Customer" />
                <select id="customer_id" name="customer_id"
                        class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->customer_id }}"
                                @selected((int) old('customer_id', $plan->customer_id ?? request('customer')) === (int) $customer->customer_id)>
                            {{ $customer->business_name }} ({{ $customer->customer_type->value }})
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-on-surface-variant mt-1">
                    Only customers assigned within {{ $companyId }} are available. The preference applies to any
                    company employee assigned to the customer; it does not choose the supplying Primary.
                </p>
                <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
            </div>

            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">Preferred visits</h2>
                    <p class="mt-1 text-xs text-on-surface-variant">
                        Rotation weeks 1–4 run continuously. Duplicate week/day rows are reused instead of inserted again.
                    </p>
                </div>
                <button type="button" @click="addVisit()" :disabled="visits.length >= 8"
                        class="inline-flex h-10 items-center rounded-full bg-secondary-container px-4 text-xs font-semibold text-on-secondary-container disabled:opacity-50">
                    + Add more
                </button>
            </div>

            <template x-for="(visit, index) in visits" :key="index">
                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2">
                    <div>
                        <label class="block text-xs text-on-surface-variant">Rotation week</label>
                        <select :name="'preferred_visits[' + index + '][preferred_week]'" x-model="visit.preferred_week"
                                class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                            <option value="">Every week</option>
                            @for ($i = 1; $i <= 4; $i++)
                                <option value="{{ $i }}">Rotation week {{ $i }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-on-surface-variant">Weekday</label>
                        <select :name="'preferred_visits[' + index + '][preferred_day]'" x-model="visit.preferred_day"
                                class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                            <template x-for="(label, value) in weekdayLabels" :key="value">
                                <option :value="value" x-text="label"></option>
                            </template>
                        </select>
                    </div>
                    <button type="button" @click="removeVisit(index)" x-show="visits.length > 1"
                            class="h-11 rounded-full bg-surface-variant px-3 text-xs text-on-surface-variant">
                        Remove
                    </button>
                </div>
            </template>

            <div>
                <x-input-error :messages="$errors->get('preferred_visits')" />
                @foreach ($errors->get('preferred_visits.*') as $messages)
                    <x-input-error :messages="$messages" />
                @endforeach
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1"
                       class="rounded border-outline text-primary focus:ring-primary"
                       @checked(old('active', $plan->exists ? $plan->active : true))>
                Active
            </label>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('fjp.index', request('company') ? ['company' => request('company')] : []) }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                {{ $plan->exists ? 'Save changes' : 'Create plan' }}
            </button>
        </div>
    </form>
</x-app-layout>
