@php
    $user = auth()->user();

    $items = [
        [
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'match' => 'dashboard',
            'icon' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
        ],
        [
            'label' => 'Customers',
            'route' => 'customers.index',
            'match' => 'customers*',
            'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '2',
        ],
        [
            'label' => 'Products',
            'route' => 'products.index',
            'match' => 'products*',
            'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN'],
            'enabled' => true,
            'phase' => '2',
        ],
        [
            'label' => 'Employees',
            'route' => 'employees.index',
            'match' => 'employees*',
            'icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN'],
            'enabled' => true,
            'phase' => '2',
        ],
        [
            'label' => 'Orders',
            'route' => 'orders.index',
            'match' => 'orders*',
            'icon' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '4',
        ],
        [
            'label' => 'Inventory',
            'route' => 'inventory.index',
            'match' => 'inventory*',
            'icon' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '5',
        ],
        [
            'label' => 'Shipments',
            'route' => 'shipments.index',
            'match' => 'shipments*',
            'icon' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '6',
        ],
        [
            'label' => 'POD',
            'route' => 'pod.index',
            'match' => 'pod*',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '7',
        ],
        [
            'label' => 'Finance',
            'route' => 'finance.invoices.index',
            'match' => 'finance*',
            'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '8',
        ],
        [
            'label' => 'Transit',
            'route' => 'transit.index',
            'match' => 'transit*',
            'icon' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '9',
        ],
        [
            'label' => $user->isSalesEmployee() ? 'Visits' : 'Journey Plans',
            'route' => $user->isSalesEmployee() ? 'visits.today' : 'fjp.index',
            'match' => $user->isSalesEmployee() ? 'visits*' : 'fjp*',
            'icon' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
            'roles' => ['SUPERADMIN', 'COMPANY_ADMIN', 'SALES_EMPLOYEE'],
            'enabled' => true,
            'phase' => '3',
        ],
    ];

    $visible = array_filter($items, fn ($item) => in_array($user->role->value, $item['roles']));
@endphp

@if ($variant === 'sidebar')
    <p class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-on-surface-variant">
        {{ $user->isSalesEmployee() ? 'Field sales' : 'Administration' }}
    </p>
    @foreach ($visible as $item)
        @if ($item['enabled'])
            <a href="{{ route($item['route']) }}"
               class="flex items-center gap-3 px-4 py-3 rounded-full text-sm font-medium
                      {{ request()->routeIs($item['match']) ? 'bg-secondary-container text-on-secondary-container' : 'text-on-surface-variant hover:bg-surface-container-high' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                </svg>
                {{ $item['label'] }}
            </a>
        @else
            <span class="flex items-center gap-3 px-4 py-3 rounded-full text-sm font-medium text-on-surface-variant/40 cursor-not-allowed"
                  title="Arrives in Phase {{ $item['phase'] }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                </svg>
                {{ $item['label'] }}
                <span class="ml-auto text-[10px] uppercase tracking-wide">P{{ $item['phase'] }}</span>
            </span>
        @endif
    @endforeach
@else
    @foreach (array_slice(array_values($visible), 0, 4) as $item)
        @if ($item['enabled'])
            <a href="{{ route($item['route']) }}"
               class="flex flex-col items-center justify-center gap-0.5 pt-2 pb-1 text-[11px] font-medium
                      {{ request()->routeIs($item['match']) ? 'text-on-surface' : 'text-on-surface-variant' }}">
                <span class="w-16 h-8 grid place-items-center rounded-full {{ request()->routeIs($item['match']) ? 'bg-secondary-container' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                    </svg>
                </span>
                {{ $item['label'] }}
            </a>
        @else
            <span class="flex flex-col items-center justify-center gap-0.5 pt-2 pb-1 text-[11px] font-medium text-on-surface-variant/40 cursor-not-allowed">
                <span class="w-16 h-8 grid place-items-center rounded-full">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                    </svg>
                </span>
                {{ $item['label'] }}
            </span>
        @endif
    @endforeach
@endif
