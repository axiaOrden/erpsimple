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

    <form method="POST" action="{{ route('inventory.counts.store') }}" class="space-y-4 max-w-2xl">
        @csrf

        <div class="m-card p-4 space-y-3">
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <x-input-label for="customer_id" value="Stock holder / customer" />
                    <select id="customer_id" name="customer_id" required
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        <option value="">Choose customer…</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->customer_id }}">{{ $c->business_name }} ({{ $c->customer_type->value }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="count_type" value="Count type" />
                    <select id="count_type" name="count_type" required
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        @foreach ($countTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="m-card p-4 space-y-3">
            <p class="text-sm font-semibold">Counted products</p>
            <div id="count-lines" class="space-y-2"
                 x-data="{ lines: [0, 1, 2] }">
                <template x-for="(line, index) in lines" :key="index">
                    <div class="flex gap-2">
                        <select :name="'lines['+index+'][product_id]'" required
                                class="flex-1 rounded-m border-outline-variant bg-surface text-sm">
                            <option value="">Product…</option>
                            @foreach ($products ?? [] as $p)
                                <option value="{{ $p->product_id }}">{{ $p->product_description }}</option>
                            @endforeach
                        </select>
                        <input type="number" :name="'lines['+index+'][counted_qty]'" step="0.001" min="0" required placeholder="Counted"
                               class="w-28 rounded-m border-outline-variant bg-surface text-sm">
                        <input type="text" :name="'lines['+index+'][count_unit]'" value="PCS" required maxlength="20"
                               class="w-20 rounded-m border-outline-variant bg-surface text-sm">
                    </div>
                </template>
                <button type="button" @click="lines.push(lines.length)" class="text-sm text-primary font-medium">+ Add line</button>
            </div>
        </div>

        <button type="submit" class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
            Save draft count
        </button>
    </form>
</x-app-layout>
