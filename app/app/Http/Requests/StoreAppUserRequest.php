<?php

namespace App\Http\Requests;

use App\Models\AppUser;
use App\Models\EmployeeMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAppUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AppUser::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', Rule::in(['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'])],
            'employee_id' => ['nullable', 'string', 'exists:employee_master,employee_id'],
            'company_id' => ['nullable', 'string', 'exists:company_master,company_id'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();

            // Company admins cannot create superadmins or cross-company users.
            if ($actor->isCompanyAdmin()) {
                if ($this->input('role') === 'SUPERADMIN') {
                    $validator->errors()->add('role', 'Company admins cannot create superadmins.');
                }

                if (filled($this->input('company_id')) && $this->input('company_id') !== $actor->company_id) {
                    $validator->errors()->add('company_id', 'Company admins can only create users in their own company.');
                }

                if (filled($this->input('employee_id'))) {
                    $employee = EmployeeMaster::find($this->input('employee_id'));

                    if ($employee !== null && $employee->company_id !== $actor->company_id) {
                        $validator->errors()->add('employee_id', 'Employee belongs to another company.');
                    }
                }
            }

            // SUPERADMIN is a platform account: no employee/company binding.
            if ($this->input('role') === 'SUPERADMIN' && (filled($this->input('employee_id')) || filled($this->input('company_id')))) {
                $validator->errors()->add('role', 'Superadmins cannot be bound to an employee or company.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()->isCompanyAdmin()) {
            $this->merge(['company_id' => $this->user()->company_id]);
        }
    }
}
