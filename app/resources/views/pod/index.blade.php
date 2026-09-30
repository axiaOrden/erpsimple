<x-app-layout>
    <x-slot name="header">
        <div>
            <h1>POD confirmations</h1>
            <p class="text-sm text-on-surface-variant">What customers confirmed as received</p>
        </div>
    </x-slot>

    <div class="space-y-3">
        @forelse ($confirmations as $confirmation)
            @php
                $item = $items->get($confirmation->delivery_no.'|'.$confirmation->delivery_item_no);
                $product = $item ? $products->get($item->product_id) : null;
                $employee = $employees->get($confirmation->confirmed_by);
            @endphp
            <div class="m-card p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $product?->product_description ?? '?' }} · {{ $confirmation->delivery_no }}</p>
                        <p class="text-xs text-on-surface-variant">
                            confirmed {{ $confirmation->confirmed_qty }} {{ $confirmation->confirmed_unit }}
                            @if ((string) $confirmation->difference_qty !== '0.000')
                                · diff {{ $confirmation->difference_qty }} {{ $confirmation->difference_unit }} ({{ $confirmation->difference_reason->value }})
                            @endif
                            · by {{ $employee?->employee_name ?? $confirmation->confirmed_by }}
                        </p>
                    </div>
                    <span class="m-chip {{ $confirmation->confirmation_status->value === 'CONFIRMED' ? 'm-chip-active' : 'm-chip-error' }}">
                        {{ $confirmation->confirmation_status->value }}
                    </span>
                </div>
            </div>
        @empty
            <div class="m-card p-6 text-center text-sm text-on-surface-variant">
                No confirmations yet. POD is captured per delivery once goods are IN_TRANSIT.
            </div>
        @endforelse
    </div>
</x-app-layout>
