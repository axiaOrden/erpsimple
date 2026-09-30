<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Products</h1>
                <p class="text-sm text-on-surface-variant">
                    {{ $products->total() }} in {{ $companyId }}
                    @can('create', \App\Models\ProductMaster::class)
                        · <a href="{{ route('products.create', request('company') ? ['company' => request('company')] : []) }}" class="text-primary font-medium">Add product</a>
                    @endcan
                </p>
            </div>

            @can('create', \App\Models\ProductMaster::class)
                <a href="{{ route('products.create', request('company') ? ['company' => request('company')] : []) }}"
                   class="inline-flex items-center gap-2 h-10 px-4 rounded-full bg-primary text-on-primary text-sm font-semibold shadow-m1">
                    + New product
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap gap-3 items-center">
        <form method="GET" class="flex-1 min-w-[240px]">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search description or SKU…"
                   class="w-full rounded-full border-outline-variant bg-surface-container"
                   aria-label="Search products">
        </form>

        @if (auth()->user()->isSuperadmin())
            <form method="GET">
                <select name="company" onchange="this.form.submit()"
                        class="rounded-full border-outline-variant bg-surface-container" aria-label="Filter by company">
                    @foreach ($companies as $c)
                        <option value="{{ $c->company_id }}" @selected($c->company_id === $companyId)>{{ $c->company_id }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($products->isEmpty())
        <div class="m-card p-8 text-center">
            <p class="font-medium text-on-surface">No products yet</p>
            <p class="text-sm text-on-surface-variant mt-1">
                Products belong to one company and use a shared unit table.
            </p>
            @can('create', \App\Models\ProductMaster::class)
                <a href="{{ route('products.create') }}" class="inline-block mt-4 text-primary font-medium">Create the first product</a>
            @endcan
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($products as $product)
                <div class="m-card p-4 flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold truncate">{{ $product->product_description }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $product->product_id }} · SKU {{ $product->product_sku }}</p>
                        </div>
                        <span class="m-chip {{ $product->active ? 'm-chip-active' : 'm-chip-error' }}">
                            {{ $product->active ? 'ACTIVE' : 'INACTIVE' }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="m-chip">Base {{ $product->basic_unit }}</span>
                        @if ($product->product_category)
                            <span class="m-chip">{{ $product->product_category }}</span>
                        @endif
                    </div>

                    @can('update', $product)
                        <div class="mt-auto flex gap-2 pt-2">
                            <a href="{{ route('products.edit', $product) }}"
                               class="flex-1 text-center h-10 inline-flex items-center justify-center rounded-full bg-primary-container text-on-primary-container text-sm font-medium">
                                Edit
                            </a>
                            @if ($product->active)
                                <form method="POST" action="{{ route('products.deactivate', $product) }}" class="contents">
                                    @csrf
                                    @method('patch')
                                    <button type="submit" onclick="return confirm('Deactivate this product? Historical orders keep their references.')"
                                            class="flex-1 h-10 rounded-full bg-error-container text-on-error-container text-sm font-medium">
                                        Deactivate
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('products.activate', $product) }}" class="contents">
                                    @csrf
                                    @method('patch')
                                    <button type="submit" class="flex-1 h-10 rounded-full bg-secondary-container text-on-secondary-container text-sm font-medium">
                                        Activate
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endcan
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    @endif
</x-app-layout>
