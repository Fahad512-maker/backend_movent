<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function shift(int $id): Shift
    {
        return Shift::whereIn('company_id', $this->activeCompanyIds())->findOrFail($id);
    }

    public function index(): JsonResponse
    {
        $shifts = Shift::whereIn('company_id', $this->activeCompanyIds())
            ->orderBy('start_time')
            ->get();

        return ApiResponse::success($shifts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id'            => ['required', 'integer', 'in:' . implode(',', $this->activeCompanyIds())],
            'name'                  => ['required', 'string', 'max:100'],
            'start_time'            => ['required', 'date_format:H:i'],
            'end_time'              => ['required', 'date_format:H:i'],
            'grace_period_minutes'  => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $shift = Shift::create($validated);

        return ApiResponse::success($shift, 'Shift created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = $this->shift($id);

        $validated = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:100'],
            'start_time'            => ['sometimes', 'date_format:H:i'],
            'end_time'              => ['sometimes', 'date_format:H:i'],
            'grace_period_minutes'  => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $shift->update($validated);

        return ApiResponse::success($shift, 'Shift updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $shift = $this->shift($id);
        $shift->delete();

        return ApiResponse::success(null, 'Shift deleted');
    }
}
