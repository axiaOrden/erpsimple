<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>New stock count</h1>
                <p class="text-sm text-on-surface-variant">Primary counts can become authoritative · secondary counts are observational</p>
            </div>
            <a href="{{ route('inventory.index') }}" class="m-chip">Cancel</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @php
        // Authorized catalog: ONLY the products this employee may count (their
        // company ∩ employee_product scope, active). It is the searchable
        // selector's fallback when the network is unavailable — never a way to
        // reach a product outside the scope, which the server re-validates on
        // submit regardless.
        $catalog = collect($products ?? [])->map(fn ($p) => [
            'id' => $p->product_id,
            'label' => $p->product_description,
            'meta' => trim(($p->product_sku ? $p->product_sku.' · ' : '').$p->product_id),
            'basic_unit' => $p->basic_unit,
        ])->values()->all();
    @endphp

    <form method="POST" action="{{ route('inventory.counts.store') }}" class="space-y-4 max-w-2xl"
          x-data="{
              catalog: @js($catalog),
              searchUrl: @js(route('search.products')),
              rows: [],
              init() { this.rows = [this.blankRow()]; },
              blankRow() {
                  return { product_id: '', label: '', basic_unit: '', qty: '', query: '', results: [], open: false, loading: false, active: 0 };
              },
              addRow() { this.rows.push(this.blankRow()); },
              // Authorized search: the server endpoint is scoped to this
              // employee's company + product scope; when it is unreachable the
              // already-scoped catalog is filtered locally instead.
              async search(row) {
                  const term = (row.query || '').trim();
                  if (term === '') { row.results = []; row.open = false; return; }
                  row.loading = true;
                  try {
                      const response = await fetch(this.searchUrl + '?q=' + encodeURIComponent(term), {
                          headers: { Accept: 'application/json' },
                      });
                      if (! response.ok) { throw new Error('search unavailable'); }
                      const payload = await response.json();
                      row.results = payload.results || [];
                  } catch (error) {
                      const needle = term.toLowerCase();
                      row.results = this.catalog.filter((p) =>
                          p.label.toLowerCase().includes(needle) || p.meta.toLowerCase().includes(needle),
                      ).slice(0, 15);
                  } finally {
                      row.loading = false;
                      row.active = 0;
                      row.open = row.results.length > 0;
                  }
              },
              move(row, step) {
                  if (row.results.length === 0) { return; }
                  row.open = true;
                  row.active = (row.active + step + row.results.length) % row.results.length;
              },
              choose(row, result) {
                  if (! result) { return; }
                  // The chosen product carries its own AUTHORITATIVE base unit:
                  // the unit field is a read-only label, never a picker and
                  // never free text.
                  row.product_id = result.id;
                  row.label = result.label;
                  row.basic_unit = result.basic_unit;
                  row.query = '';
                  row.results = [];
                  row.open = false;
              },
              clearProduct(row) { row.product_id = ''; row.label = ''; row.basic_unit = ''; },
              unitFor(row) { return row.basic_unit || '—'; },
              rowStarted(row) { return row.product_id !== '' || row.qty !== '' || (row.query || '') !== ''; },
              dropEmptyRows(event) {
                  // A count may legitimately cover ONE product. Rows the employee
                  // never started are disabled at submit time so they are not
                  // sent (and cannot block the browser's own required-field
                  // check for the rows that WERE filled in).
                  event.target.querySelectorAll('[data-count-line]').forEach((line) => {
                      const started = line.dataset.started === '1';
                      line.querySelectorAll('input, select, textarea').forEach((field) => { field.disabled = ! started; });
                  });
              }
          }"
          @submit="dropEmptyRows($event)">
        @csrf

        <div class="m-card p-4 space-y-3">
            @if ($lockedCustomer)
                <input type="hidden" name="customer_id" value="{{ $lockedCustomer->customer_id }}">
                <input type="hidden" name="count_type" value="{{ $lockedCountType }}">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs text-on-surface-variant">Counting inventory for</p>
                        <p class="font-semibold">{{ $lockedCustomer->business_name }}</p>
                        <p class="text-xs text-on-surface-variant">{{ $lockedCustomer->customer_id }} · {{ $lockedCustomer->customer_type->value }}</p>
                    </div>
                    <span class="m-chip m-chip-active">{{ $countTypes[$lockedCountType] }}</span>
                </div>
                <p class="text-xs text-on-surface-variant">
                    Customer and count type were set from the customer page and cannot be changed here.
                </p>
            @else
                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="customer_id" value="Stock holder / customer" />
                        <select id="customer_id" name="customer_id" required
                                class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                            <option value="">Choose customer…</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->customer_id }}" @selected((int) old('customer_id') === (int) $c->customer_id)>{{ $c->business_name }} ({{ $c->customer_type->value }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="count_type" value="Count type" />
                        <select id="count_type" name="count_type" required
                                class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                            @foreach ($countTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('count_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif
        </div>

        <div class="m-card p-4 space-y-3">
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-sm font-semibold">Counted products</p>
                <p class="text-[11px] text-on-surface-variant">Counted in each product's basic unit</p>
            </div>

            <div id="count-lines" class="space-y-2">
                <template x-for="(row, index) in rows" :key="index">
                    {{-- Product (searchable) / Qty / Unit (read-only): every field
                         stacks, so nothing overflows at 360 px. --}}
                    <div class="rounded-m border border-outline-variant p-2 space-y-2" data-count-line
                         :data-started="rowStarted(row) ? '1' : '0'">
                        <div class="min-w-0"
                             @keydown.escape="row.open = false"
                             @click.outside="row.open = false">
                            <label class="block text-[11px] text-on-surface-variant" :for="'count-product-' + index">Product</label>

                            <div class="relative mt-0.5">
                                <input :id="'count-product-' + index" type="search" x-model="row.query"
                                       @input.debounce.250ms="search(row)"
                                       @focus="row.open = row.results.length > 0"
                                       @keydown.arrow-down.prevent="move(row, 1)"
                                       @keydown.arrow-up.prevent="move(row, -1)"
                                       @keydown.enter.prevent="choose(row, row.results[row.active])"
                                       role="combobox" aria-autocomplete="list"
                                       :aria-expanded="row.open ? 'true' : 'false'"
                                       placeholder="Search product name, description or code…"
                                       :required="row.product_id === '' && (index === 0 || rowStarted(row))"
                                       class="w-full min-w-0 rounded-m border-outline-variant bg-surface text-sm">

                                {{-- The SUBMITTED product id is the chosen id only —
                                     never free text typed by the employee. --}}
                                <input type="hidden" :name="'lines['+index+'][product_id]'" :value="row.product_id">

                                <ul x-show="row.open" x-cloak role="listbox"
                                    class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-m border border-outline-variant bg-surface shadow-m1">
                                    <template x-for="(result, r) in row.results" :key="result.id">
                                        <li role="option" :aria-selected="r === row.active ? 'true' : 'false'">
                                            <button type="button" @click="choose(row, result)"
                                                    class="flex w-full items-start justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-surface-variant"
                                                    :class="r === row.active ? 'bg-surface-variant' : ''">
                                                <span class="min-w-0">
                                                    <span class="block truncate" x-text="result.label"></span>
                                                    <span class="block text-[11px] text-on-surface-variant" x-text="result.meta"></span>
                                                </span>
                                                <span class="shrink-0 text-[11px] font-medium text-on-surface-variant" x-text="result.basic_unit"></span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="! row.loading && row.results.length === 0"
                                        class="px-3 py-2 text-[11px] text-on-surface-variant">
                                        No authorized product matches that search.
                                    </li>
                                </ul>
                            </div>

                            <p class="mt-0.5 text-[11px] text-on-surface-variant" x-show="row.loading" x-cloak>Searching…</p>

                            <div class="mt-0.5 flex items-center justify-between gap-2" x-show="row.product_id" x-cloak>
                                <p class="min-w-0 truncate text-[11px] text-on-surface-variant">
                                    Selected: <span class="font-medium" x-text="row.label"></span>
                                </p>
                                <button type="button" @click="clearProduct(row)"
                                        class="shrink-0 text-[11px] font-medium text-primary">Change</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="min-w-0">
                                <label class="block text-[11px] text-on-surface-variant" :for="'count-qty-' + index">Qty</label>
                                <input :id="'count-qty-' + index" type="number" x-model="row.qty" :name="'lines['+index+'][counted_qty]'"
                                       step="0.001" min="0" inputmode="decimal"
                                       :required="index === 0 || rowStarted(row)" placeholder="Counted"
                                       class="mt-0.5 w-full min-w-0 rounded-m border-outline-variant bg-surface text-sm">
                            </div>
                            <div class="min-w-0">
                                <label class="block text-[11px] text-on-surface-variant">Unit</label>
                                <p class="mt-0.5 grid h-[2.375rem] place-items-center rounded-m bg-surface-variant text-sm font-medium"
                                   x-text="unitFor(row)"
                                   :aria-label="'Base unit (read-only): ' + unitFor(row)"></p>
                                {{-- Authoritative base unit of the SELECTED product: submitted
                                     as-is, never chosen and never typed. --}}
                                <input type="hidden" :name="'lines['+index+'][count_unit]'" :value="row.basic_unit">
                            </div>
                        </div>
                    </div>
                </template>

                <button type="button" @click="addRow()" class="h-10 w-full rounded-full bg-secondary-container text-sm font-medium text-on-secondary-container">
                    + Add line
                </button>
            </div>
        </div>

        <button type="submit" class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
            Save draft count
        </button>
    </form>
</x-app-layout>
