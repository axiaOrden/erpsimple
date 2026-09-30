<x-guest-layout>
    <div class="m-card p-6 sm:p-8 w-full max-w-md">
        <div class="flex flex-col items-center text-center mb-6">
            <span class="w-14 h-14 rounded-2xl bg-primary text-on-primary grid place-items-center text-2xl font-bold mb-3">E</span>
            <h1 class="text-xl font-semibold">{{ config('app.name', 'Simple ERP') }}</h1>
            <p class="text-sm text-on-surface-variant mt-1">Sign in to your field sales account</p>
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                              :value="old('email')" required autofocus autocomplete="username"
                              placeholder="you@company.com" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password"
                              required autocomplete="current-password" placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-on-surface-variant">
                <input id="remember_me" type="checkbox" name="remember"
                       class="rounded border-outline text-primary focus:ring-primary bg-surface">
                {{ __('Remember me') }}
            </label>

            <button type="submit"
                    class="w-full inline-flex justify-center items-center rounded-full bg-primary px-4 py-2.5 text-sm font-semibold text-on-primary shadow-m1 hover:shadow-m2 active:scale-[.99] transition">
                {{ __('Log in') }}
            </button>

            @if (Route::has('password.request'))
                <p class="text-center text-sm">
                    <a class="text-primary font-medium hover:underline" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                </p>
            @endif
        </form>
    </div>
</x-guest-layout>
