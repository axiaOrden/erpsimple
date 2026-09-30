<?php

namespace App\Http\Controllers;

use App\Models\CompanyMaster;
use App\Models\ProductMaster;
use App\Models\UnitMaster;
use App\Services\CompanyContext;
use App\Services\ProductUnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ProductUnitConversionService $unitConversionService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ProductMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $products = ProductMaster::query()
            ->forCompany($companyId)
            ->search($request->query('q'))
            ->with(['basicUnit', 'company'])
            ->orderBy('product_id')
            ->paginate(20)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'companyId' => $companyId,
            'companies' => $this->companyChoices(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', ProductMaster::class);

        return view('products.form', [
            'product' => new ProductMaster,
            'units' => UnitMaster::orderBy('unit_code')->get(),
            'companies' => $this->companyChoices(),
            'companyId' => $this->companyContext->companyId(),
            'conversions' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', ProductMaster::class);

        $companyId = $this->resolveCompanyId($request);

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:50', 'unique:product_master,product_id'],
            'product_description' => ['required', 'string', 'max:255'],
            'product_category' => ['nullable', 'string', 'max:100'],
            'product_sku' => [
                'required', 'string', 'max:100',
                Rule::unique('product_master', 'product_sku')->where('company_id', $companyId),
            ],
            'sku_description' => ['nullable', 'string', 'max:255'],
            'basic_unit' => ['required', 'exists:unit_master,unit_code'],
            'ext_product_id' => ['nullable', 'string', 'max:100'],
            'issuing_company' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $validated['company_id'] = $companyId;
        $validated['active'] = $request->boolean('active', true);

        $product = ProductMaster::create($validated);

        // Optional alternative-unit conversions (validated in the service).
        $conversionErrors = $this->syncConversions($request, $product);

        if ($conversionErrors !== []) {
            return back()->withErrors(['unit_conversions' => $conversionErrors])->withInput();
        }

        return redirect()
            ->route('products.index', $request->query('company') !== null ? ['company' => $companyId] : [])
            ->with('status', 'Product created.');
    }

    public function edit(ProductMaster $product)
    {
        $this->authorize('update', $product);

        return view('products.form', [
            'product' => $product,
            'units' => UnitMaster::orderBy('unit_code')->get(),
            'companies' => $this->companyChoices(),
            'companyId' => $product->company_id,
            'conversions' => $product->unitConversions()->get(['alternative_unit', 'numerator', 'denominator']),
        ]);
    }

    public function update(Request $request, ProductMaster $product)
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'product_description' => ['required', 'string', 'max:255'],
            'product_category' => ['nullable', 'string', 'max:100'],
            'product_sku' => [
                'required', 'string', 'max:100',
                Rule::unique('product_master', 'product_sku')
                    ->where('company_id', $product->company_id)
                    ->ignore($product->product_id, 'product_id'),
            ],
            'sku_description' => ['nullable', 'string', 'max:255'],
            'basic_unit' => ['required', 'exists:unit_master,unit_code'],
            'ext_product_id' => ['nullable', 'string', 'max:100'],
            'issuing_company' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $validated['active'] = $request->boolean('active', false);

        $product->update($validated);

        // Optional alternative-unit conversions (validated in the service).
        $conversionErrors = $this->syncConversions($request, $product);

        if ($conversionErrors !== []) {
            return back()->withErrors(['unit_conversions' => $conversionErrors])->withInput();
        }

        return redirect()
            ->route('products.index', $request->query('company') !== null ? ['company' => $product->company_id] : [])
            ->with('status', 'Product updated.');
    }

    public function deactivate(ProductMaster $product)
    {
        $this->authorize('delete', $product);

        $product->update(['active' => false]);

        return back()->with('status', 'Product deactivated.');
    }

    public function activate(ProductMaster $product)
    {
        $this->authorize('update', $product);

        $product->update(['active' => true]);

        return back()->with('status', 'Product activated.');
    }

    /**
     * Extract and persist the alternative-unit conversions submitted with a
     * product form. Returns the service's validation errors (empty = ok).
     *
     * @return list<string>
     */
    private function syncConversions(Request $request, ProductMaster $product): array
    {
        $submitted = $request->input('conversions', []);

        if (! is_array($submitted)) {
            return [];
        }

        $conversions = [];

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $unit = strtoupper(trim((string) ($row['alternative_unit'] ?? '')));

            if ($unit === '') {
                continue; // skip blank rows
            }

            $conversions[] = [
                'alternative_unit' => $unit,
                'numerator' => $row['numerator'] ?? null,
                'denominator' => $row['denominator'] ?? null,
            ];
        }

        return $this->unitConversionService->syncConversions($product, $conversions);
    }

    private function resolveCompanyId(Request $request): string
    {
        $user = $request->user();

        if ($user->isSuperadmin()) {
            $requested = $request->query('company') ?? $request->input('company_id');

            if (is_string($requested) && $requested !== '' && CompanyMaster::where('company_id', $requested)->exists()) {
                return $requested;
            }
        }

        return $this->companyContext->companyId();
    }

    private function companyChoices()
    {
        $user = auth()->user();

        if ($user->isSuperadmin()) {
            return CompanyMaster::where('active', true)->orderBy('company_id')->get();
        }

        return CompanyMaster::where('company_id', $user->company_id)->get();
    }
}
