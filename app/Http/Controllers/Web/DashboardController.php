<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Services\Analytics\DemandForecastService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DemandForecastService $forecastService,
    ) {}

    /**
     * Redirect authenticated user to their role-specific landing dashboard.
     */
    public function index(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if ($user && $user->role) {
            $map = config('permissions.role_redirect_map', []);
            $roleName = $user->role->name;

            if (isset($map[$roleName])) {
                return redirect()->to($map[$roleName]);
            }
        }

        return view('dashboard', [
            'user' => $user,
            'roleConfig' => null,
        ]);
    }

    /**
     * Display the role-specific workspace dashboard.
     */
    public function show(Request $request, string $role): View
    {
        $user = $request->user();
        $normalizedSlug = Str::kebab(str_replace('_', '-', $role));
        $dashboards = config('permissions.role_dashboards', []);

        if (!isset($dashboards[$normalizedSlug])) {
            abort(Response::HTTP_NOT_FOUND, 'Dashboard not found.');
        }

        $roleConfig = $dashboards[$normalizedSlug];

        if (!$user->hasRole($roleConfig['role_name']) && !$user->hasRole($normalizedSlug)) {
            abort(Response::HTTP_FORBIDDEN, 'Access denied. You do not have permission to access this dashboard.');
        }

        $upcomingDemand = [];
        $canViewForecast = $user->isSuperAdmin()
            || $user->hasPermission('ml.forecast.view')
            || $user->hasRole(['admin', 'super-admin', 'branch-manager', 'inventory-manager', 'purchase-manager', 'system-analyst']);

        if ($canViewForecast) {
            if (Product::query()->count() === 0 || Branch::query()->count() === 0) {
                app(DemoDataSeeder::class)->run();
            }
            $branchId = (int) ($user->branch_id ?: (Branch::query()->where('status', 'active')->value('id') ?? 1));
            if ($branchId > 0 && Branch::whereKey($branchId)->exists()) {
                $upcomingDemand = $this->forecastService->ensureBranchForecasts($branchId);
            }
        }

        return view('dashboard', [
            'user' => $user,
            'roleConfig' => $roleConfig,
            'roleSlug' => $normalizedSlug,
            'permissions' => $user->getPermissions(),
            'upcomingDemand' => $upcomingDemand,
        ]);
    }
}
