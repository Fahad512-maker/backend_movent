<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAdmin;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'total_packages'       => Package::count(),
            'total_admins'         => CompanyAdmin::count(),
            'total_companies'      => Company::count(),
            'active_subscriptions' => CompanyAdmin::where('subscription_status', 'active')->count(),
            'recent_admins'        => CompanyAdmin::with('package')
                ->withCount('companies')
                ->latest()
                ->take(5)
                ->get(),
            'recent_companies'     => Company::with('admin')
                ->withCount('users')
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }
}
