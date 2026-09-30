<?php

namespace App\Http\Requests;

use App\Models\AppUser;
use App\Models\EmployeeMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAppUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        /** @var AppUser $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['nullable', Password::defaults()],
            'role' => ['sometimes', Rule::in(['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'])],
            'employee_id' => ['nullable', 'string', 'exists:employee_master,employee_id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            /** @var AppUser $target */
            $target = $this->route('user');

            if ($actor->isCompanyAdmin()) {
                if ($this->filled('role') && $this->input('role') !== $target->role->value) {
                    $validator->errors()->add('role', 'Company admins cannot change roles.');
                }

                if ($this->filled('employee_id')) {
                    $employee = EmployeeMaster::find($this->input('employee_id'));

                    if ($employee !== null && $employee->company_id !== $actor->company_id) {
                        $validator->errors()->add('employee_id', 'Employee belongs to another company.');
                    }
                }
            }

            // A superadmin account must never become employee/company-bound.
            if ($target->isSuperadmin() && $this->filled('employee_id')) {
                $validator->errors()->add('employee_id', 'Superadmins cannot be bound to an employee.');
            }
        });
    }
}
