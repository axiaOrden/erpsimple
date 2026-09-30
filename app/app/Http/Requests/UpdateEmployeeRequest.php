<?php

namespace App\Http\Requests;

use App\Models\EmployeeMaster;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', EmployeeMaster::class);
    }

    public function rules(): array
    {
        return [
            'employee_name' => ['required', 'string', 'max:255'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
