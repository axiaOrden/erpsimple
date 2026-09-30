<x-app-layout>
    <x-slot name="header">
        <h1>{{ $company?->company_name ?? 'Company' }}</h1>
        <p class="text-sm text-on-surface-variant">Company administration</p>
    </x-slot>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="m-card p-4">
            <p class="text-xs uppercase tracking-wide text-on-surface-variant">Products</p>
            <p class="text-3xl font-semibold mt-1">{{ $productCount }}</p>
            <p class="text-xs text-on-surface-variant mt-1">active · Phase 2</p>
        </div>
        <div class="m-card p-4">
            <p class="text-xs uppercase tracking-wide text-on-surface-variant">Employees</p>
            <p class="text-3xl font-semibold mt-1">{{ $employeeCount }}</p>
            <p class="text-xs text-on-surface-variant mt-1">active · Phase 2</p>
        </div>
        <div class="m-card p-4">
            <p class="text-xs uppercase tracking-wide text-on-surface-variant">App users</p>
            <p class="text-3xl font-semibold mt-1">{{ $userCount }}</p>
            <p class="text-xs text-on-surface-variant mt-1">active accounts</p>
        </div>
        <div class="m-card p-4">
            <p class="text-xs uppercase tracking-wide text-on-surface-variant">Customers</p>
            <p class="text-3xl font-semibold mt-1">{{ $customerCount }}</p>
            <p class="text-xs text-on-surface-variant mt-1">global (shared)</p>
        </div>
    </div>

    <section class="m-card p-6">
        <h2>Operational modules</h2>
        <p class="text-sm text-on-surface-variant mt-1">
            Orders, deliveries, shipments, invoices and payments arrive in Phases 4–7.
            Master data management (products, employees, assignments, pricing, deals)
            lands in Phase 2.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            <span class="m-chip">Master data · P2</span>
            <span class="m-chip">Field sales · P3</span>
            <span class="m-chip">Orders · P4</span>
            <span class="m-chip">Inventory · P5</span>
            <span class="m-chip">Logistics · P6</span>
            <span class="m-chip">Finance · P7</span>
        </div>
    </section>
</x-app-layout>
