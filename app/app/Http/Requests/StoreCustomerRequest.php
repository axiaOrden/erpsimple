<?php

namespace App\Http\Requests;

use App\Enums\CustomerType;
use App\Models\CustomerMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CustomerMaster::class);
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', Rule::in(['PRIMARY', 'SECONDARY', 'VAN', 'SHIP_TO'])],
            // SHIP_TO must reference an existing PRIMARY parent; others must not have one.
            'parent_customer_id' => [
                'nullable',
                'integer',
                'exists:customer_master,customer_id',
                Rule::requiredIf(fn () => $this->input('customer_type') === 'SHIP_TO'),
                Rule::prohibitedIf(fn () => $this->filled('parent_customer_id') && $this->input('customer_type') !== 'SHIP_TO'),
            ],
            'ext_origin_id' => ['nullable', 'string', 'max:100'],
            'ext_origin_company' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'sales_region' => ['nullable', 'string', 'max:100'],
            'market' => ['nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        // SHIP_TO parent must be a PRIMARY customer.
        $validator->after(function ($validator) {
            if ($this->input('customer_type') === 'SHIP_TO' && $this->filled('parent_customer_id')) {
                $parent = CustomerMaster::find($this->input('parent_customer_id'));

                if ($parent === null || $parent->customer_type !== CustomerType::PRIMARY) {
                    $validator->errors()->add('parent_customer_id', 'A SHIP_TO location must belong to a PRIMARY customer.');
                }
            }
        });
    }
}
