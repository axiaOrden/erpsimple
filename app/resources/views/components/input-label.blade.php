@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold text-on-surface-variant']) }}>
    {{ $value ?? $slot }}
</label>
