<button {{ $attributes->merge(['type' => 'button', 'class' => 'm-button-tonal disabled:opacity-40']) }}>
    {{ $slot }}
</button>
