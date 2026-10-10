<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\JobApplicant;
use App\Models\Recruitment;
use App\Models\SystemAuditLog;
use App\Rules\ValidPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecruitmentController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function recruitment(int $id): Recruitment
    {
        return Recruitment::whereIn('company_id', $this->companyIds())->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Recruitment::whereIn('company_id', $this->activeCompanyIds())
            ->withCount('applicants');

        if ($request->filled('status')) $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id'  => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'position'    => ['required', 'string', 'max:150'],
            'department'  => ['nullable', 'string', 'max:100'],
            'openings'    => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $validated['openings'] ??= 1;
        $validated['status'] = 'open';
        // created_by FKs to `users`; Company Admin actor isn't a User row.
        $validated['created_by'] = null;

        $recruitment = Recruitment::create($validated);

        SystemAuditLog::create([
            'company_id'  => $recruitment->company_id,
            'user_id'     => null,
            'action'      => 'recruitment_created',
            'module_key'  => 'hr',
            'entity_type' => 'Recruitment',
            'entity_id'   => $recruitment->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($recruitment, 'Job posting created', 201);
    }

    public function show(int $id): JsonResponse
    {
        $recruitment = $this->recruitment($id)->load(['applicants' => fn ($q) => $q->orderByDesc('created_at')]);

        return ApiResponse::success($recruitment);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $recruitment = $this->recruitment($id);

        $validated = $request->validate([
            'position'    => ['sometimes', 'string', 'max:150'],
            'department'  => ['nullable', 'string', 'max:100'],
            'openings'    => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:open,closed,on_hold'],
        ]);

        $recruitment->update($validated);

        SystemAuditLog::create([
            'company_id'  => $recruitment->company_id,
            'user_id'     => null,
            'action'      => 'recruitment_updated',
            'module_key'  => 'hr',
            'entity_type' => 'Recruitment',
            'entity_id'   => $recruitment->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($recruitment, 'Job posting updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $recruitment = $this->recruitment($id);

        SystemAuditLog::create([
            'company_id'  => $recruitment->company_id,
            'user_id'     => null,
            'action'      => 'recruitment_deleted',
            'module_key'  => 'hr',
            'entity_type' => 'Recruitment',
            'entity_id'   => $recruitment->id,
            'new_values'  => ['position' => $recruitment->position],
        ]);

        $recruitment->delete();

        return ApiResponse::success(null, 'Job posting deleted');
    }

    // ── Applicants ("Manage Applicants") ───────────────────────────────────

    public function addApplicant(Request $request, int $id): JsonResponse
    {
        $recruitment = $this->recruitment($id);

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['recruitment_id'] = $recruitment->id;
        $validated['status'] = 'applied';

        $applicant = JobApplicant::create($validated);

        SystemAuditLog::create([
            'company_id'  => $recruitment->company_id,
            'user_id'     => null,
            'action'      => 'applicant_added',
            'module_key'  => 'hr',
            'entity_type' => 'Recruitment',
            'entity_id'   => $recruitment->id,
            'new_values'  => ['applicant_id' => $applicant->id, 'name' => $applicant->name],
        ]);

        return ApiResponse::success($applicant, 'Applicant added', 201);
    }

    public function updateApplicantStatus(Request $request, int $id, int $applicantId): JsonResponse
    {
        $recruitment = $this->recruitment($id);

        $applicant = JobApplicant::where('recruitment_id', $recruitment->id)->findOrFail($applicantId);

        $validated = $request->validate(['status' => ['required', 'in:applied,shortlisted,interviewed,hired,rejected']]);

        $applicant->update(['status' => $validated['status']]);

        SystemAuditLog::create([
            'company_id'  => $recruitment->company_id,
            'user_id'     => null,
            'action'      => 'applicant_' . $validated['status'],
            'module_key'  => 'hr',
            'entity_type' => 'Recruitment',
            'entity_id'   => $recruitment->id,
            'new_values'  => ['applicant_id' => $applicant->id, 'status' => $validated['status']],
        ]);

        return ApiResponse::success($applicant, 'Applicant status updated');
    }
}
