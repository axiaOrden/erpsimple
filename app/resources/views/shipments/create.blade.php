<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1>New shipment</h1>
                <p class="text-sm text-on-surface-variant">A dispatch load from ONE source customer</p>
            </div>
            <a href="{{ route('shipments.index') }}" class="m-chip">Back</a>
        </div>
    </x-slot>

    @if ($errors->any())
        <div class="mb-4 bg-error-container text-on-error-container rounded-m p-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('shipments.store') }}" class="space-y-4 max-w-2xl">
        @csrf

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Source (stock holder)</span>
            <select name="source_customer_id" required class="w-full rounded-m border-outline-variant bg-surface text-sm">
                <option value="">Choose source…</option>
                @foreach ($sources as $source)
                    <option value="{{ $source->customer_id }}">
                        {{ $source->business_name }} ({{ $source->customer_type }})
                    </option>
                @endforeach
            </select>
        </label>

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Vehicle reference (optional)</span>
            <input type="text" name="vehicle_reference" maxlength="100"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm" placeholder="e.g. van-01 / plate">
        </label>

        <label class="m-card p-4 block space-y-1">
            <span class="text-xs text-on-surface-variant">Remarks (optional)</span>
            <input type="text" name="remarks" maxlength="255"
                   class="w-full rounded-m border-outline-variant bg-surface text-sm">
        </label>

        <button type="submit"
                class="w-full h-12 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
            Create shipment
        </button>
    </form>
</x-app-layout>
