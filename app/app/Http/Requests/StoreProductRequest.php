<?php

namespace App\Http\Requests;

use App\Models\ProductMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductMaster::class);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'max:50', 'unique:product_master,product_id'],
            'company_id' => ['required', 'string', 'exists:company_master,company_id'],
            'product_description' => ['required', 'string', 'max:255'],
            'product_category' => ['nullable', 'string', 'max:100'],
            'product_sku' => [
                'required', 'string', 'max:100',
                Rule::unique('product_master', 'product_sku')->where('company_id', $this->input('company_id')),
            ],
            'sku_description' => ['nullable', 'string', 'max:255'],
            'basic_unit' => ['required', 'string', 'exists:unit_master,unit_code'],
            'ext_product_id' => ['nullable', 'string', 'max:100'],
            'issuing_company' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
