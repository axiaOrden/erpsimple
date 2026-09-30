<x-app-layout>
    <x-slot name="header">
        <h1>{{ $employee->exists ? 'Edit employee' : 'New employee' }}</h1>
        <p class="text-sm text-on-surface-variant">Company: {{ $companyId }}</p>
    </x-slot>

    <form method="POST"
          action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store', request('company') ? ['company' => request('company')] : []) }}"
          class="max-w-2xl space-y-5"
          x-data="{ createLogin: {{ old('create_login', $employee->exists ? 'false' : 'false') }} }">
        @csrf
        @if ($employee->exists)
            @method('patch')
        @endif

        <div class="m-card p-6 space-y-4">
            @unless ($employee->exists)
                <div>
                    <x-input-label for="employee_id" value="Employee ID" />
                    <x-text-input id="employee_id" name="employee_id" type="text" class="mt-1 block w-full"
                                  value="{{ old('employee_id') }}" required maxlength="50" placeholder="e.g. EMP-SE-002" />
                    <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
                </div>
            @endunless

            <div>
                <x-input-label for="employee_name" value="Full name" />
                <x-text-input id="employee_name" name="employee_name" type="text" class="mt-1 block w-full"
                              value="{{ old('employee_name', $employee->employee_name) }}" required maxlength="255" />
                <x-input-error :messages="$errors->get('employee_name')" class="mt-2" />
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="email_address" value="Email (optional)" />
                    <x-text-input id="email_address" name="email_address" type="email" class="mt-1 block w-full"
                                  value="{{ old('email_address', $employee->email_address) }}" maxlength="255" />
                </div>
                <div>
                    <x-input-label for="phone_number" value="Phone" />
                    <x-text-input id="phone_number" name="phone_number" type="tel" class="mt-1 block w-full"
                                  value="{{ old('phone_number', $employee->phone_number) }}" maxlength="50" />
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="active" value="1"
                       class="rounded border-outline text-primary focus:ring-primary"
                       @checked(old('active', $employee->exists ? $employee->active : true))>
                Active
            </label>
        </div>

        @unless ($employee->exists)
            <div class="m-card p-6 space-y-4">
                <h2>Login account</h2>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="create_login" value="1" x-model="createLogin"
                           class="rounded border-outline text-primary focus:ring-primary"
                           @checked(old('create_login'))>
                    Provision an app login for this employee
                </label>

                <div x-show="createLogin" x-transition class="space-y-4 pt-2">
                    <div>
                        <x-input-label for="login_email" value="Login email" />
                        <x-text-input id="login_email" name="login_email" type="email" class="mt-1 block w-full"
                                      value="{{ old('login_email') }}" maxlength="255" />
                        <x-input-error :messages="$errors->get('login_email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="login_password" value="Initial password" />
                        <x-text-input id="login_password" name="login_password" type="password" class="mt-1 block w-full"
                                      autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('login_password')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="login_role" value="Role" />
                        <select id="login_role" name="login_role"
                                class="mt-1 block w-full rounded-m border-outline-variant bg-surface">
                            <option value="SALES_EMPLOYEE" @selected(old('login_role') === 'SALES_EMPLOYEE')>Sales employee</option>
                            <option value="COMPANY_ADMIN" @selected(old('login_role') === 'COMPANY_ADMIN')>Company admin</option>
                        </select>
                        <x-input-error :messages="$errors->get('login_role')" class="mt-2" />
                    </div>
                </div>
            </div>
        @endunless

        <div class="flex gap-3">
            <a href="{{ route('employees.index', request('company') ? ['company' => request('company')] : []) }}"
               class="h-11 inline-flex items-center px-5 rounded-full bg-surface-variant text-on-surface-variant font-medium">
                Cancel
            </a>
            <button type="submit"
                    class="h-11 inline-flex items-center px-6 rounded-full bg-primary text-on-primary font-semibold shadow-m1">
                {{ $employee->exists ? 'Save changes' : 'Create employee' }}
            </button>
        </div>
    </form>
</x-app-layout>
