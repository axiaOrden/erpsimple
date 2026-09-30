<x-app-layout>
    <x-slot name="header">
        <h1>Profile</h1>
        <p class="text-sm text-on-surface-variant">Account details are managed by your administrator</p>
    </x-slot>

    <div class="max-w-xl space-y-6">
        <div class="m-card p-6">
            <h2 class="mb-4">Account</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Name</dt>
                    <dd class="font-medium text-right">{{ $user->name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Email</dt>
                    <dd class="font-medium text-right">{{ $user->email }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Role</dt>
                    <dd><span class="m-chip m-chip-active">{{ $user->role->value }}</span></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Company</dt>
                    <dd class="font-medium">{{ $user->company?->company_name ?? '— (superadmin)' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-on-surface-variant">Employee</dt>
                    <dd class="font-medium">{{ $user->employee?->employee_name ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="m-card p-6">
            <h2 class="mb-4">Display name</h2>
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
                @csrf
                @method('patch')
                <div>
                    <x-input-label for="name" :value="__('Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div class="flex justify-end">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        </div>

        <div class="m-card p-6">
            <h2 class="mb-4">Password</h2>
            @include('profile.partials.update-password-form')
        </div>
    </div>
</x-app-layout>
