<button {{ $attributes->merge(['type' => 'submit', 'class' => 'm-button-filled']) }}>
    {{ $slot }}
</button>
