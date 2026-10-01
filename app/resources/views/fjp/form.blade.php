<x-app-layout>
    <x-slot name="header">
        <h1>{{ $plan->exists ? 'Edit journey plan' : 'New journey plan' }}</h1>
        <p class="text-sm text-on-surface-variant">Company: {{ $companyId }} · primarily for Secondary customer visits</p>
    </x-slot>

    <form method="POST"
          action="{{ $plan->exists ? route('fjp.update', $plan) : route('fjp.store', request('company') ? ['company' => request('company')] : []) }}"
          class="max-w-xl space-y-5">
        @csrf
        @if ($plan->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            <div>
                <x-input-label for="employee_id" value="Sales employee" />
                <select id="employee_id" name="employee_id"
                        class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->employee_id }}"
                                @selected(old('employee_id', $plan->employee_id) === $employee->employee_id)>
                            {{ $employee->employee_name }} ({{ $employee->employee_id }})
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="customer_id" value="Customer" />
                <select id="customer_id" name="customer_id"
                        class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->customer_id }}"
                                @selected(old('customer_id', $plan->customer_id) === $customer->customer_id)>
                            {{ $customer->business_name }} ({{ $customer->customer_type->value }})
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-on-surface-variant mt-1">
                    FJP schedules the visit only — it does not choose the supplying Primary.
                </p>
                <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="preferred_week" value="Rotation week (optional)" />
                    <select id="preferred_week" name="preferred_week"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        <option value="">Every week</option>
                        @for ($i = 1; $i <= 4; $i++)
                            <option value="{{ $i }}" @selected(old('preferred_week', $plan->preferred_week) == $i)>Rotation week {{ $i }}</option>
                        @endfor
                    </select>
                    <p class="text-xs text-on-surface-variant mt-1">
                        Continuous 4-week cycle (1→2→3→4→1…) — not week-of-month.
                        Stored as a numeric rotation week + weekday (0 = Sunday … 6 = Saturday).
                    </p>
                </div>
                <div>
                    <x-input-label for="preferred_day" value="Weekday" />
                    <select id="preferred_day" name="preferred_day"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface" required>
                        @foreach ($days as $day)
                            <option value="{{ $day }}" @selected((int) old('preferred_day', $plan->preferred_day) === $day)>
                                {{ \App\Enums\Weekday::from($day)->label() }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('preferred_day')" class="mt-2" />
                </div>
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
