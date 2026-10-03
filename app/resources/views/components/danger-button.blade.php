<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-full bg-error px-5 text-sm font-bold text-white shadow-m1 hover:shadow-m2 focus:ring-2 focus:ring-error/30 disabled:opacity-40']) }}>
    {{ $slot }}
</button>
