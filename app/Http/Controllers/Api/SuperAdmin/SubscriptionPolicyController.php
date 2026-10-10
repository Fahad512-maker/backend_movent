<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The single global Trial/Suspension/Subscription policy Super Admin
// controls — see the subscription_policies migration and
// App\Models\SubscriptionPolicy for what each field does. Always operates on
// the one singleton row (SubscriptionPolicy::current()); there is no
// index/store/destroy, only show/update.
class SubscriptionPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success(SubscriptionPolicy::current());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trial_enabled'                    => ['required', 'boolean'],
            'trial_duration_days'              => ['required', 'integer', 'min:0', 'max:365'],
            // Null/empty = every module available during trial.
            'trial_modules'                     => ['nullable', 'array'],
            'trial_modules.*'                   => ['string'],
            'grace_period_days'                 => ['required', 'integer', 'min:0', 'max:90'],
            'renewal_reminder_days'              => ['required', 'array', 'min:1'],
            'renewal_reminder_days.*'            => ['integer', 'min:0'],
            'auto_suspend_on_trial_expiry'       => ['required', 'boolean'],
            'auto_suspend_on_renewal_failure'    => ['required', 'boolean'],
            'trial_expiry_behavior'              => ['required', 'in:grace_period,suspend_immediately'],
            'renewal_failure_behavior'           => ['required', 'in:grace_period,suspend_immediately'],
            'payment_policy_notes'               => ['nullable', 'string', 'max:5000'],
        ]);

        $policy = SubscriptionPolicy::current();
        $validated['updated_by_super_admin_id'] = $request->user('super_admin')->id;
        $policy->update($validated);

        return ApiResponse::success($policy->fresh(), 'Subscription policy updated');
    }
}
