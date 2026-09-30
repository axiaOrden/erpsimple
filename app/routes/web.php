<?php

use App\Http\Controllers\AppUserController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FjpController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PodController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\TransitController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Offline fallback for the service worker (public on purpose).
Route::get('/offline', fn () => view('offline'))->name('offline');

// Login/password routes manage their own guest middleware.
require __DIR__.'/auth.php';

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // SUPERADMIN company switcher
    Route::post('/company/switch', [CompanySwitchController::class, 'switch'])
        ->middleware('can:switch-company')
        ->name('company.switch');

    // ---- Searchable selectors (JSON, server-side) ----
    Route::get('/search/customers', [SearchController::class, 'customers'])->name('search.customers');
    Route::get('/search/supplying-primaries', [SearchController::class, 'supplyingPrimaries'])->name('search.supplying-primaries');
    Route::get('/search/products', [SearchController::class, 'products'])->name('search.products');
    Route::get('/search/employees', [SearchController::class, 'employees'])->name('search.employees');

    // ---- Master data: products ----
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::patch('/products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
    Route::patch('/products/{product}/activate', [ProductController::class, 'activate'])->name('products.activate');

    // ---- Master data: customers (global; edit restricted to superadmin) ----
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::patch('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
    Route::patch('/customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');

    // ---- Master data: employees ----
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::patch('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate'])->name('employees.deactivate');
    Route::patch('/employees/{employee}/activate', [EmployeeController::class, 'activate'])->name('employees.activate');

    // ---- Assignments per employee (customers + product scope) ----
    Route::get('/employees/{employee}/assignments', [AssignmentController::class, 'edit'])->name('assignments.edit');
    Route::put('/employees/{employee}/assignments', [AssignmentController::class, 'update'])->name('assignments.update');

    // ---- App user accounts ----
    Route::get('/users', [AppUserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [AppUserController::class, 'create'])->name('users.create');
    Route::post('/users', [AppUserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [AppUserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [AppUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/deactivate', [AppUserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('/users/{user}/activate', [AppUserController::class, 'activate'])->name('users.activate');

    // ---- Field sales: visits & attendance (Phase 3) ----
    Route::get('/visits', [VisitController::class, 'today'])->name('visits.today');
    Route::get('/visits/customers/{customer}', [VisitController::class, 'customer'])->name('visits.customer');
    Route::post('/visits/attendance', [VisitController::class, 'storeAttendance'])->name('visits.attendance');

    // Offline sync (idempotent ingestion)
    Route::post('/sync/attendance', [VisitController::class, 'syncAttendance'])->name('sync.attendance');

    // ---- Sales Orders (Phase 4: demand only, no inventory effect) ----
    Route::get('/orders', [SalesOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [SalesOrderController::class, 'create'])->name('orders.create');
    Route::match(['get', 'post'], '/orders/context', [SalesOrderController::class, 'context'])->name('orders.context');
    Route::post('/orders', [SalesOrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}/edit', [SalesOrderController::class, 'edit'])->name('orders.edit');
    Route::patch('/orders/{order}', [SalesOrderController::class, 'update'])->name('orders.update');
    Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/items/{itemNo}/reject', [SalesOrderController::class, 'rejectItem'])->name('orders.items.reject');

    // Offline draft sync (idempotent)
    Route::post('/sync/order-drafts', [SalesOrderController::class, 'syncDraft'])->name('sync.order-drafts');

    // ---- Inventory (Phase 5: visibility, adjustments, stock counts) ----
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::get('/inventory/counts', [InventoryController::class, 'indexCounts'])->name('inventory.counts.index');
    Route::get('/inventory/counts/create', [InventoryController::class, 'createCount'])->name('inventory.counts.create');
    Route::post('/inventory/counts', [InventoryController::class, 'storeCount'])->name('inventory.counts.store');
    Route::get('/inventory/counts/{count}', [InventoryController::class, 'showCount'])->name('inventory.counts.show');
    Route::post('/inventory/counts/{count}/submit', [InventoryController::class, 'submitCount'])->name('inventory.counts.submit');

    // ---- Deliveries (Phase 5: allocation) ----
    Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('/orders/{order}/deliveries/create', [DeliveryController::class, 'create'])->name('deliveries.create');
    Route::post('/orders/{order}/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
    Route::get('/deliveries/{deliveryNo}', [DeliveryController::class, 'show'])->name('deliveries.show');
    Route::post('/deliveries/{deliveryNo}/release', [DeliveryController::class, 'release'])->name('deliveries.release');
    Route::post('/deliveries/{deliveryNo}/reallocate', [DeliveryController::class, 'reallocate'])->name('deliveries.reallocate');

    // ---- Shipments (Phase 6: dispatch + Goods Issue at START) ----
    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('/shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
    Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
    Route::post('/shipments/{shipment}/deliveries', [ShipmentController::class, 'attach'])->name('shipments.attach');
    Route::delete('/shipments/{shipment}/deliveries', [ShipmentController::class, 'detach'])->name('shipments.detach');
    Route::post('/shipments/{shipment}/ready', [ShipmentController::class, 'ready'])->name('shipments.ready');
    Route::post('/shipments/{shipment}/back-to-draft', [ShipmentController::class, 'backToDraft'])->name('shipments.back-to-draft');
    Route::post('/shipments/{shipment}/start', [ShipmentController::class, 'start'])->name('shipments.start');

    // ---- POD (Phase 7: delivery confirmation) ----
    Route::get('/pod', [PodController::class, 'index'])->name('pod.index');
    Route::get('/pod/{delivery}', [PodController::class, 'show'])->name('pod.show');
    Route::post('/pod/{delivery}/confirm', [PodController::class, 'confirm'])->name('pod.confirm');

    // ---- Transit stock (Phase 9: issued-but-unaccepted stock) ----
    Route::get('/transit', [TransitController::class, 'index'])->name('transit.index');
    Route::get('/transit/{transit}', [TransitController::class, 'show'])->name('transit.show');
    Route::post('/transit/{transit}/return', [TransitController::class, 'initiateReturn'])->name('transit.return');
    Route::post('/transit/{transit}/verify-receipt', [TransitController::class, 'verifyReceipt'])->name('transit.verify-receipt');
    Route::post('/transit/{transit}/resolve-found', [TransitController::class, 'resolveFound'])->name('transit.resolve-found');
    Route::post('/transit/{transit}/resolve-damaged', [TransitController::class, 'resolveDamaged'])->name('transit.resolve-damaged');
    Route::post('/transit/{transit}/resolve-lost', [TransitController::class, 'resolveLost'])->name('transit.resolve-lost');
    Route::post('/transit/{transit}/write-off', [TransitController::class, 'writeOff'])->name('transit.write-off');

    // ---- Finance (Phase 8: invoices, payments, credits) ----
    Route::get('/invoices', [FinanceController::class, 'invoices'])->name('finance.invoices.index');
    Route::get('/invoices/{invoice}', [FinanceController::class, 'invoice'])->name('finance.invoices.show');
    Route::get('/payments', [FinanceController::class, 'payments'])->name('finance.payments.index');
    Route::get('/payments/create', [FinanceController::class, 'createPayment'])->name('finance.payments.create');
    Route::post('/payments', [FinanceController::class, 'storePayment'])->name('finance.payments.store');
    Route::get('/payments/{payment}', [FinanceController::class, 'payment'])->name('finance.payments.show');
    Route::post('/payments/{payment}/confirm', [FinanceController::class, 'confirmPayment'])->name('finance.payments.confirm');
    Route::post('/payments/{payment}/cancel', [FinanceController::class, 'cancelPayment'])->name('finance.payments.cancel');
    Route::post('/payments/{payment}/allocate', [FinanceController::class, 'allocatePayment'])->name('finance.payments.allocate');
    Route::post('/payments/{payment}/convert-remainder', [FinanceController::class, 'convertRemainder'])->name('finance.payments.convert-remainder');
    Route::get('/credits', [FinanceController::class, 'credits'])->name('finance.credits.index');
    Route::get('/credits/create', [FinanceController::class, 'createCredit'])->name('finance.credits.create');
    Route::post('/credits', [FinanceController::class, 'storeCredit'])->name('finance.credits.store');
    Route::get('/credits/{credit}', [FinanceController::class, 'credit'])->name('finance.credits.show');
    Route::post('/credits/{credit}/allocate', [FinanceController::class, 'allocateCredit'])->name('finance.credits.allocate');
    Route::get('/customers/{customer}/statement', [FinanceController::class, 'statement'])->name('finance.statement');

    // ---- Fixed Journey Plan (admin) ----
    Route::get('/fjp', [FjpController::class, 'index'])->name('fjp.index');
    Route::get('/fjp/create', [FjpController::class, 'create'])->name('fjp.create');
    Route::post('/fjp', [FjpController::class, 'store'])->name('fjp.store');
    Route::get('/fjp/{plan}/edit', [FjpController::class, 'edit'])->name('fjp.edit');
    Route::patch('/fjp/{plan}', [FjpController::class, 'update'])->name('fjp.update');
    Route::patch('/fjp/{plan}/deactivate', [FjpController::class, 'deactivate'])->name('fjp.deactivate');
    Route::patch('/fjp/{plan}/activate', [FjpController::class, 'activate'])->name('fjp.activate');
});
