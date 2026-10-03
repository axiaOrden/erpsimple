@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-m bg-primary-container px-4 py-3 text-sm font-semibold text-on-primary-container']) }}>
        {{ $status }}
    </div>
@endif
