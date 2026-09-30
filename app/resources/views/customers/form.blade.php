<x-app-layout>
    <x-slot name="header">
        <h1>{{ $customer->exists ? 'Edit customer' : 'New customer' }}</h1>
        <p class="text-sm text-on-surface-variant">Customers are global and shared by all companies</p>
    </x-slot>

    <form method="POST"
          action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}"
          class="max-w-2xl space-y-5">
        @csrf
        @if ($customer->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            @unless ($customer->exists)
                <div>
                    <x-input-label for="customer_id" value="Customer ID" />
                    <x-text-input id="customer_id" name="customer_id" type="text" class="mt-1 block w-full"
                                  value="{{ old('customer_id') }}" required maxlength="50" placeholder="e.g. MIMZA" />
                    <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                </div>
            @endunless

            <div>
                <x-input-label for="business_name" value="Business name" />
                <x-text-input id="business_name" name="business_name" type="text" class="mt-1 block w-full"
                              value="{{ old('business_name', $customer->business_name) }}" required maxlength="255" />
                <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="customer_type" value="Customer type" />
                    <select id="customer_type" name="customer_type"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface"
                            x-data
                            @change="$el.closest('form').querySelector('[data-parent-field]').classList.toggle('hidden', $el.value !== 'SHIP_TO')">
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(old('customer_type', $customer->customer_type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('customer_type')" class="mt-2" />
                </div>

                <div data-parent-field class="{{ old('customer_type', $customer->customer_type) === 'SHIP_TO' ? '' : 'hidden' }}">
                    <x-input-label for="parent_customer_id" value="Parent (PRIMARY) — SHIP_TO only" />
                    <select id="parent_customer_id" name="parent_customer_id"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        <option value="">—</option>
                        @foreach ($primaries as $primary)
                            <option value="{{ $primary->customer_id }}"
                                    @selected(old('parent_customer_id', $customer->parent_customer_id) === $primary->customer_id)>
                                {{ $primary->customer_id }} — {{ $primary->business_name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('parent_customer_id')" class="mt-2" />
                </div>
            </div>

            <p class="text-xs text-on-surface-variant">
                No fixed Primary → Secondary relationship exists in the data model; the commercial
                link is the employee assignment. SHIP_TO is the only typed parent relationship.
            </p>
        </div>

        <div class="m-card p-6 space-y-4">
            <h2>Contact & location</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="contact_person" value="Contact person" />
                    <x-text-input id="contact_person" name="contact_person" type="text" class="mt-1 block w-full"
                                  value="{{ old('contact_person', $customer->contact_person) }}" maxlength="255" />
                </div>
                <div>
                    <x-input-label for="phone_number" value="Phone" />
                    <x-text-input id="phone_number" name="phone_number" type="tel" class="mt-1 block w-full"
                                  value="{{ old('phone_number', $customer->phone_number) }}" maxlength="50" />
                </div>
            </div>

            <div>
                <x-input-label for="email_address" value="Email" />
                <x-text-input id="email_address" name="email_address" type="email" class="mt-1 block w-full"
                              value="{{ old('email_address', $customer->email_address) }}" maxlength="255" />
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="address" value="Address" />
                    <x-text-input id="address" name="address" type="text" class="mt-1 block w-full"
                                  value="{{ old('address', $customer->address) }}" maxlength="255" />
                </div>
                <div>
                    <x-input-label for="city" value="City" />
                    <x-text-input id="city" name="city" type="text" class="mt-1 block w-full"
                                  value="{{ old('city', $customer->city) }}" maxlength="100" />
                </div>
            </div>

            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="state" value="State" />
                    <x-text-input id="state" name="state" type="text" class="mt-1 block w-full"
                                  value="{{ old('state', $customer->state) }}" maxlength="100" />
                </div>
                <div>
                    <x-input-label for="sales_region" value="Sales region" />
                    <x-text-input id="sales_region" name="sales_region" type="text" class="mt-1 block w-full"
                                  value="{{ old('sales_region', $customer->sales_region) }}" maxlength="100" />
                </div>
                <div>
                    <x-input-label for="market" value="Market" />
                    <x-text-input id="market" name="market" type="text" class="mt-1 block w-full"
                                  value="{{ old('market', $customer->market) }}" maxlength="100" />
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1"
                       class="rounded border-outline text-primary focus:ring-primary"
                       @checked(old('active', $customer->exists ? $customer->active : true))>
                Active
            </label>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('customers.index') }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                {{ $customer->exists ? 'Save changes' : 'Create customer' }}
            </button>
        </div>
    </form>
</x-app-layout>
