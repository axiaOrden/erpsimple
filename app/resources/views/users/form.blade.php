<x-app-layout>
    <x-slot name="header">
        <h1>{{ $user->exists ? 'Edit user' : 'New user' }}</h1>
        <p class="text-sm text-on-surface-variant">Login accounts, roles and company binding</p>
    </x-slot>

    <form method="POST"
          action="{{ $user->exists ? route('users.update', $user) : route('users.store', request('company') ? ['company' => request('company')] : []) }}"
          class="max-w-2xl space-y-5">
        @csrf
        @if ($user->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            <div>
                <x-input-label for="name" value="Display name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                              value="{{ old('name', $user->name) }}" required maxlength="255" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="email" value="Login email" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                              value="{{ old('email', $user->email) }}"
                              :disabled="$user->exists" required maxlength="255" />
                @if ($user->exists)
                    <p class="text-xs text-on-surface-variant mt-1">Email cannot be changed (login identity).</p>
                @endif
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="$user->exists ? 'New password (leave blank to keep)' : 'Initial password'" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                              autocomplete="new-password" :required="! $user->exists" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="role" value="Role" />
                    <select id="role" name="role"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface"
                            @if (auth()->user()->isCompanyAdmin() && $user->exists) disabled @endif>
                        @if (auth()->user()->isSuperadmin())
                            <option value="SUPERADMIN" @selected(old('role', $user->role?->value) === 'SUPERADMIN')>Superadmin</option>
                        @endif
                        <option value="COMPANY_ADMIN" @selected(old('role', $user->role?->value) === 'COMPANY_ADMIN')>Company admin</option>
                        <option value="SALES_EMPLOYEE" @selected(old('role', $user->role?->value) === 'SALES_EMPLOYEE')>Sales employee</option>
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="employee_id" value="Employee (optional)" />
                    <select id="employee_id" name="employee_id"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        <option value="">— none —</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->employee_id }}"
                                    @selected(old('employee_id', $user->employee_id) === $employee->employee_id)>
                                {{ $employee->employee_id }} — {{ $employee->employee_name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
                </div>
            </div>

            @unless ($user->exists)
                <div>
                    <x-input-label for="company_id" value="Company" />
                    <select id="company_id" name="company_id"
                            class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                        <option value="">— none (superadmin only) —</option>
                        @foreach ($companies as $c)
                            <option value="{{ $c->company_id }}" @selected(old('company_id', $companyId) === $c->company_id)>
                                {{ $c->company_id }} — {{ $c->company_name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
                </div>
            @endunless

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1"
                       class="rounded border-outline text-primary focus:ring-primary"
                       @checked(old('active', $user->exists ? $user->active : true))>
                Active (may log in)
            </label>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('users.index') }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                {{ $user->exists ? 'Save changes' : 'Create user' }}
            </button>
        </div>
    </form>
</x-app-layout>
