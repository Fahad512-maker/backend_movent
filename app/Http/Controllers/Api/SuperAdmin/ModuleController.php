<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModuleController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Module::orderBy('label')->get());
    }

    public function store(Request $request): JsonResponse
    {
        // price_pkr is deliberately NOT accepted here — USD is the system's
        // only supported currency now. New modules keep that column's own DB
        // default; existing ones keep whatever value they already had.
        $validated = $request->validate([
            'key'         => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('modules', 'key')],
            'label'       => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_usd'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $module = Module::create([
            'key'         => $validated['key'],
            'label'       => $validated['label'],
            'description' => $validated['description'] ?? null,
            'sub_modules' => [$validated['key']],
            'price_usd'   => $validated['price_usd'] ?? 0,
            'is_active'   => true,
            'is_system'   => false,
        ]);

        return ApiResponse::success($module, 'Module created', 201);
    }

    public function update(Request $request, Module $module): JsonResponse
    {
        $validated = $request->validate([
            'label'       => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_usd'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $module->update([
            'label'       => $validated['label'],
            'description' => $validated['description'] ?? null,
            'price_usd'   => $validated['price_usd'] ?? 0,
        ]);

        return ApiResponse::success($module, 'Module updated');
    }

    public function toggle(Module $module): JsonResponse
    {
        $module->update(['is_active' => !$module->is_active]);

        return ApiResponse::success(['is_active' => $module->is_active]);
    }

    public function destroy(Module $module): JsonResponse
    {
        if ($module->is_system) {
            return ApiResponse::error('System modules cannot be deleted. Deactivate it instead.', 422);
        }

        $module->delete();

        return ApiResponse::success(null, 'Module deleted');
    }
}
