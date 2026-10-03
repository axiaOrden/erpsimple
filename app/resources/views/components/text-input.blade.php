@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-m border-outline-variant bg-surface-container-low text-on-surface shadow-none focus:border-primary focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-60']) }}>
