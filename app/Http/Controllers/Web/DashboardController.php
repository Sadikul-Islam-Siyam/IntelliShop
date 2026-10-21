<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    /**
     * Redirect authenticated user to their role-specific landing dashboard.
     */
    public function index(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        // Check if user has an assigned role with a dedicated redirect route
        if ($user && $user->role) {
            $map = config('permissions.role_redirect_map', []);
            $roleName = $user->role->name;

            if (isset($map[$roleName])) {
                return redirect()->to($map[$roleName]);
            }
        }

        // Fallback: render default dashboard view
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

        // Validate that requested dashboard slug exists
        if (!isset($dashboards[$normalizedSlug])) {
            abort(Response::HTTP_NOT_FOUND, 'Dashboard not found.');
        }

        $roleConfig = $dashboards[$normalizedSlug];

        // Verify that user possesses the required role for this dashboard
        if (!$user->hasRole($roleConfig['role_name']) && !$user->hasRole($normalizedSlug)) {
            abort(Response::HTTP_FORBIDDEN, 'Access denied. You do not have permission to access this dashboard.');
        }

        return view('dashboard', [
            'user' => $user,
            'roleConfig' => $roleConfig,
            'roleSlug' => $normalizedSlug,
            'permissions' => $user->getPermissions(),
        ]);
    }
}
