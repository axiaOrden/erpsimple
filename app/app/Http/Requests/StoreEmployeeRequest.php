<?php

namespace App\Http\Requests;

use App\Models\EmployeeMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeMaster::class);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:50', 'unique:employee_master,employee_id'],
            'company_id' => ['required', 'string', 'exists:company_master,company_id'],
            'employee_name' => ['required', 'string', 'max:255'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
            // Optional login account provisioned with the employee.
            'create_login' => ['sometimes', 'boolean'],
            'login_email' => ['nullable', 'required_if:create_login,1,true', 'email', 'max:255', 'unique:app_user,email'],
            'login_password' => ['nullable', 'required_if:create_login,1,true', Password::defaults()],
            'login_role' => ['nullable', 'required_if:create_login,1,true', Rule::in(['COMPANY_ADMIN', 'SALES_EMPLOYEE'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A company admin can only provision logins inside their own company.
        if ($this->user()->isCompanyAdmin()) {
            $this->merge(['company_id' => $this->user()->company_id]);
        }
    }
}
