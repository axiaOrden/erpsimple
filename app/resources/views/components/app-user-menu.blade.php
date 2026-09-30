<div x-data="{ open: false }" class="relative">
    <button type="button" @click="open = ! open"
            class="w-10 h-10 rounded-full grid place-items-center bg-primary-container text-on-primary-container font-semibold hover:shadow-m1 transition-shadow"
            aria-label="Account menu">
        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
    </button>

    <div x-show="open" @click.outside="open = false" x-transition.opacity
         class="absolute right-0 mt-2 w-72 m-card shadow-m3 p-4 z-50">
        <p class="font-semibold">{{ auth()->user()->name }}</p>
        <p class="text-sm text-on-surface-variant">{{ auth()->user()->email }}</p>

        <div class="mt-2 flex flex-wrap gap-2">
            <span class="m-chip m-chip-active">{{ auth()->user()->role->value }}</span>
            @if (auth()->user()->company)
                <span class="m-chip">{{ auth()->user()->company->company_id }}</span>
            @endif
        </div>

        @can('switch-company')
            <form method="POST" action="{{ route('company.switch') }}" class="mt-4">
                @csrf
                <label for="company-switch" class="block text-xs font-medium text-on-surface-variant mb-1">
                    Working company
                </label>
                <div class="flex gap-2">
                    <select id="company-switch" name="company"
                            class="flex-1 rounded-m border-outline-variant bg-surface text-sm">
                        @foreach (\App\Models\CompanyMaster::where('active', true)->orderBy('company_id')->get() as $c)
                            <option value="{{ $c->company_id }}"
                                    @selected($c->company_id === auth()->user()->currentCompanyId())>
                                {{ $c->company_id }} — {{ $c->company_name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit"
                            class="px-3 rounded-full bg-primary text-on-primary text-sm font-medium hover:shadow-m1">
                        Set
                    </button>
                </div>
            </form>
        @endcan

        <a href="{{ route('profile.edit') }}"
           class="mt-4 block text-sm font-medium text-primary hover:underline">
            Profile & password
        </a>
    </div>
</div>
