@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full border-l-4 border-primary bg-primary-container py-3 pe-4 ps-3 text-start text-base font-semibold text-on-primary-container transition duration-150 ease-in-out'
            : 'block w-full border-l-4 border-transparent py-3 pe-4 ps-3 text-start text-base font-medium text-on-surface-variant transition duration-150 ease-in-out hover:border-outline hover:bg-primary/5 hover:text-on-surface';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
