<x-app-layout>
    <x-slot name="header">
        <h1>{{ $product->exists ? 'Edit product' : 'New product' }}</h1>
        <p class="text-sm text-on-surface-variant">Company: {{ $companyId }}</p>
    </x-slot>

    <form method="POST"
          action="{{ $product->exists ? route('products.update', $product) : route('products.store', request('company') ? ['company' => request('company')] : []) }}"
          class="max-w-2xl space-y-5">
        @csrf
        @if ($product->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            @unless ($product->exists)
                <div>
                    <x-input-label for="product_id" value="Product ID" />
                    <x-text-input id="product_id" name="product_id" type="text" class="mt-1 block w-full"
                                  value="{{ old('product_id') }}" required maxlength="50"
                                  placeholder="e.g. PRD-0001" />
                    <x-input-error :messages="$errors->get('product_id')" class="mt-2" />
                </div>
            @endunless

            <div>
                <x-input-label for="product_description" value="Description" />
                <x-text-input id="product_description" name="product_description" type="text"
                              class="mt-1 block w-full" value="{{ old('product_description', $product->product_description) }}"
                              required maxlength="255" />
                <x-input-error :messages="$errors->get('product_description')" class="mt-2" />
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="product_sku" value="SKU" />
                    <x-text-input id="product_sku" name="product_sku" type="text" class="mt-1 block w-full"
                                  value="{{ old('product_sku', $product->product_sku) }}" required maxlength="100" />
                    <x-input-error :messages="$errors->get('product_sku')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="basic_unit" value="Basic unit" />
                    <select id="basic_unit" name="basic_unit"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->unit_code }}"
                                    @selected(old('basic_unit', $product->basic_unit ?? 'PCS') === $unit->unit_code)>
                                {{ $unit->unit_code }} — {{ $unit->unit_description }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('basic_unit')" class="mt-2" />
                </div>
            </div>

            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="product_category" value="Category (optional)" />
                    <x-text-input id="product_category" name="product_category" type="text" class="mt-1 block w-full"
                                  value="{{ old('product_category', $product->product_category) }}" maxlength="100" />
                </div>
                <div>
                    <x-input-label for="issuing_company" value="Issuing company (optional)" />
                    <x-text-input id="issuing_company" name="issuing_company" type="text" class="mt-1 block w-full"
                                  value="{{ old('issuing_company', $product->issuing_company) }}" maxlength="255" />
                </div>
                <div>
                    <x-input-label for="ext_product_id" value="External product ID (optional)" />
                    <x-text-input id="ext_product_id" name="ext_product_id" type="text" class="mt-1 block w-full"
                                  value="{{ old('ext_product_id', $product->ext_product_id) }}" maxlength="100" />
                    <x-input-error :messages="$errors->get('ext_product_id')" class="mt-2" />
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1"
                       class="rounded border-outline text-primary focus:ring-primary"
                       @checked(old('active', $product->exists ? $product->active : true))>
                Active
            </label>
        </div>

        <div class="m-card p-6"
             x-data="{ rows: {{ old('conversions')
                 ? json_encode(collect(old('conversions'))->map(fn ($c) => [
                     'unit' => $c['alternative_unit'] ?? '',
                     'numerator' => $c['numerator'] ?? '1',
                     'denominator' => $c['denominator'] ?? '1',
                 ])->values())
                 : json_encode($conversions->map(fn ($c) => [
                     'unit' => $c->alternative_unit,
                     'numerator' => $c->numerator,
                     'denominator' => $c->denominator,
                 ])->values()) }} }">
            <h2>Alternative units</h2>
            <p class="text-sm text-on-surface-variant mt-1">
                Basic unit: <span class="m-chip m-chip-active">{{ $product->basic_unit ?? old('basic_unit', 'PCS') }}</span>
                — alternative quantity × numerator ÷ denominator = basic quantity
                (e.g. 1 CTN = 24 PCS ⇒ 24 ÷ 1).
            </p>

            @error('unit_conversions')
                <div class="mt-3 bg-error-container text-on-error-container rounded-m p-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ((array) $message as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @enderror

            <div class="mt-4 space-y-3">
                <template x-for="(row, index) in rows" :key="index">
                    <div class="grid grid-cols-[1fr_auto] gap-2 items-end">
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs font-medium text-on-surface-variant mb-1"
                                       :for="'conv-unit-'+index">Unit</label>
                                <select :id="'conv-unit-'+index" x-model="row.unit"
                                        class="w-full rounded-m border-outline-variant bg-surface text-sm">
                                    <option value="">—</option>
                                    @foreach ($units as $unit)
                                        @unless ($unit->unit_code === ($product->basic_unit ?? old('basic_unit')))
                                            <option value="{{ $unit->unit_code }}">{{ $unit->unit_code }} — {{ $unit->unit_description }}</option>
                                        @endunless
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-on-surface-variant mb-1"
                                       :for="'conv-num-'+index">× numerator</label>
                                <input type="number" step="0.000001" min="0.000001" x-model="row.numerator"
                                       :id="'conv-num-'+index"
                                       class="w-full rounded-m border-outline-variant bg-surface text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-on-surface-variant mb-1"
                                       :for="'conv-den-'+index">÷ denominator</label>
                                <input type="number" step="0.000001" min="0.000001" x-model="row.denominator"
                                       :id="'conv-den-'+index"
                                       class="w-full rounded-m border-outline-variant bg-surface text-sm">
                            </div>
                        </div>
                        <button type="button" @click="rows.splice(index, 1)"
                                class="h-10 w-10 grid place-items-center rounded-full bg-error-container text-on-error-container"
                                aria-label="Remove conversion">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </template>

                <p x-show="rows.length === 0" class="text-sm text-on-surface-variant">
                    No alternative units. Orders can still use the basic unit.
                </p>
            </div>

            <button type="button" @click="rows.push({ unit: '', numerator: '1', denominator: '1' })"
                    class="mt-3 h-10 px-4 rounded-full bg-secondary-container text-on-secondary-container text-sm font-medium">
                + Add alternative unit
            </button>

            {{-- hidden inputs so plain POST submits all rows --}}
            <template x-for="(row, index) in rows" :key="'hidden-'+index">
                <span>
                    <input type="hidden" :name="'conversions['+index+'][alternative_unit]'" :value="row.unit">
                    <input type="hidden" :name="'conversions['+index+'][numerator]'" :value="row.numerator">
                    <input type="hidden" :name="'conversions['+index+'][denominator]'" :value="row.denominator">
                </span>
            </template>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('products.index', request('company') ? ['company' => request('company')] : []) }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                {{ $product->exists ? 'Save changes' : 'Create product' }}
            </button>
        </div>
    </form>
</x-app-layout>
