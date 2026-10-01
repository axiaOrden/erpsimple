<?php

namespace App\Http\Controllers;

use App\Models\EmployeeMaster;
use App\Services\FieldDirectoryService;
use App\Services\SalesLifecycleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The field-sales workspaces that are NOT a global module: the Primary tab,
 * the Secondary customer directory and the More hub. Every screen is scoped
 * to the signed-in employee's assignments and company.
 */
class FieldController extends Controller
{
    public function __construct(
        private readonly FieldDirectoryService $directory,
        private readonly SalesLifecycleService $lifecycle,
    ) {}

    /** PRIMARY tab: assigned Primary customers + visit/count context. */
    public function primary(Request $request): View
    {
        $employee = $this->employee($request);

        return view('field.primary', [
            'employee' => $employee,
            'rows' => $this->directory->primaryDirectory($employee),
        ]);
    }

    /**
     * SECONDARY tab: the assigned customer directory with search and
     * mobile-friendly "load more" growth (per_page doubles; the list
     * accumulates instead of paging through numbered links).
     */
    public function secondary(Request $request): View
    {
        $employee = $this->employee($request);
        $search = $request->query('q');
        $perPage = $this->perPage($request);

        return view('field.secondary', [
            'employee' => $employee,
            'customers' => $this->directory->secondaryDirectory($employee, is_string($search) ? $search : null, $perPage),
            'search' => is_string($search) ? $search : null,
            'perPage' => $perPage,
            'nextPerPage' => min(120, $perPage * 2),
        ]);
    }

    /** More hub: everything that is not one of the five primary destinations. */
    public function more(Request $request): View
    {
        $employee = $this->employee($request);

        return view('field.more', [
            'employee' => $employee,
            'attention' => $this->directory->attention($employee, $this->lifecycle),
        ]);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        if ($perPage < 5) {
            $perPage = 15;
        }

        return min(120, $perPage);
    }

    private function employee(Request $request): EmployeeMaster
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 403, 'Only sales employees have a field workspace.');

        return $employee;
    }
}
