<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeNote;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\SystemAuditLog;
use App\Rules\ValidPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    use ScopesToActiveCompany;

    private const MAX_FILE_KB = 10240; // 10 MB per file
    private const ALLOWED_MIMES = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg';

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function employee(int $id): Employee
    {
        return Employee::whereIn('company_id', $this->companyIds())->findOrFail($id);
    }

    private function logActivity(int $companyId, string $action, int $entityId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => null, // Company Admin actor isn't a User row
            'action'      => $action,
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $entityId,
            'new_values'  => $newValues,
        ]);
    }

    private function attachTodayAttendance($employees)
    {
        $collection = $employees instanceof Employee ? collect([$employees]) : $employees;
        $employees->loadMissing('shift:id,name,start_time,end_time,grace_period_minutes');

        foreach ($collection->groupBy('company_id') as $companyId => $companyEmployees) {
            $company = Company::find($companyId);
            if (!$company) continue;

            $date = Carbon::now($company->timezone ?: 'UTC')->toDateString();
            $rows = Attendance::where('company_id', $companyId)
                ->whereDate('date', $date)
                ->whereIn('employee_id', $companyEmployees->pluck('id'))
                ->get()
                ->keyBy('employee_id');

            foreach ($companyEmployees as $employee) {
                $row = $rows->get($employee->id);
                $displayStatus = 'not_checked_in';
                if ($row && $row->check_in && !$row->check_out) {
                    $displayStatus = 'working';
                } elseif ($row && $row->check_in && $row->check_out) {
                    $displayStatus = $row->status;
                } elseif ($row) {
                    $displayStatus = $row->status === 'pending' ? 'not_checked_in' : $row->status;
                }

                $employee->setAttribute('today_attendance', [
                    'id' => $row?->id,
                    'date' => $date,
                    'display_status' => $displayStatus,
                    'status' => $row?->status,
                    'check_in' => $row?->check_in,
                    'check_out' => $row?->check_out,
                    'worked_minutes' => $row?->worked_minutes,
                    'late_by_minutes' => $row?->late_by_minutes,
                    'overtime_minutes' => $row?->overtime_minutes,
                ]);
            }
        }

        return $employees;
    }

    public function index(Request $request): JsonResponse
    {
        $q = Employee::whereIn('company_id', $this->activeCompanyIds())
            ->with('company:id,name', 'createdBy:id,name', 'createdByAdmin:id,name');

        if ($request->filled('department')) $q->where('department', $request->department);
        if ($request->filled('status'))     $q->where('status', $request->status);
        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        return ApiResponse::success($this->attachTodayAttendance($q->orderByDesc('created_at')->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->operationalCompanyIds();

        $validated = $request->validate([
            'company_id'      => ['required', 'integer', 'in:' . implode(',', $companyIds)],
            'user_id'         => ['nullable', 'integer', 'exists:users,id'],
            'employee_code'   => ['nullable', 'string', 'max:50', 'unique:employees,employee_code'],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => [
                'nullable', 'email', 'max:255',
                Rule::unique('employees', 'email')->where(
                    fn ($q) => $q->where('company_id', $request->integer('company_id'))
                ),
            ],
            'phone'           => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'department'      => [
                'nullable', 'string', 'max:100',
                Rule::exists('departments', 'name')->where(
                    fn ($q) => $q->where('company_id', $request->integer('company_id'))
                ),
            ],
            'designation'     => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,intern'],
            'shift_id'        => ['nullable', 'integer', Rule::exists('shifts', 'id')->where(fn ($q) => $q->whereIn('company_id', $companyIds))],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_end_time'   => ['nullable', 'date_format:H:i'],
            'salary'          => ['nullable', 'numeric', 'min:0'],
            'join_date'       => ['nullable', 'date'],
        ]);

        $validated['employee_code'] ??= 'EMP-' . strtoupper(Str::random(6));
        $validated['employment_type'] ??= 'full_time';
        $validated['status'] = 'active';
        $validated['created_by_admin_id'] = $this->admin()->id;

        // The Shift is the source of truth for timing once selected — the
        // cached shift_start_time/shift_end_time columns follow it rather
        // than whatever raw values the client happened to send.
        if (!empty($validated['shift_id'])) {
            $shift = Shift::find($validated['shift_id']);
            $validated['shift_start_time'] = $shift->start_time;
            $validated['shift_end_time'] = $shift->end_time;
        }

        $employee = Employee::create($validated);

        $this->logActivity($employee->company_id, 'created', $employee->id, $validated);

        if ($employee->user_id) {
            Notification::create([
                'user_id'    => $employee->user_id,
                'company_id' => $employee->company_id,
                'type'       => 'employee_profile_created',
                'title'      => 'Your employee profile was created',
                'body'       => "An HR profile ({$employee->employee_code}) was created for you.",
                'data'       => ['employee_id' => $employee->id],
            ]);
        }

        return ApiResponse::success($employee, 'Employee created', 201);
    }

    public function show(int $id): JsonResponse
    {
        $employee = $this->employee($id)->load([
            'attendances' => fn ($q) => $q->orderByDesc('date')->limit(10),
            'leaveRequests' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
            'payrolls' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
            'notes' => fn ($q) => $q->with('authorAdmin:id,name')->orderByDesc('created_at'),
            'documents' => fn ($q) => $q->orderByDesc('created_at'),
            'user:id,name,email',
            'company:id,name',
            'shift:id,name,start_time,end_time',
            'createdBy:id,name',
            'createdByAdmin:id,name',
        ]);

        return ApiResponse::success($this->attachTodayAttendance($employee));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $this->employee($id);
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'user_id'         => ['nullable', 'integer', 'exists:users,id'],
            'employee_code'   => ['nullable', 'string', 'max:50', 'unique:employees,employee_code,' . $employee->id],
            'name'            => ['sometimes', 'string', 'max:150'],
            'email'           => [
                'nullable', 'email', 'max:255',
                Rule::unique('employees', 'email')->where(
                    fn ($q) => $q->where('company_id', $employee->company_id)
                )->ignore($employee->id),
            ],
            'phone'           => ['nullable', 'string', 'max:30', new ValidPhoneNumber],
            'department'      => [
                'nullable', 'string', 'max:100',
                Rule::exists('departments', 'name')->where(
                    fn ($q) => $q->where('company_id', $employee->company_id)
                ),
            ],
            'designation'     => ['nullable', 'string', 'max:100'],
            'employment_type' => ['sometimes', 'in:full_time,part_time,contract,intern'],
            'shift_id'        => ['nullable', 'integer', Rule::exists('shifts', 'id')->where(fn ($q) => $q->whereIn('company_id', $companyIds))],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_end_time'   => ['nullable', 'date_format:H:i'],
            'salary'          => ['nullable', 'numeric', 'min:0'],
            'join_date'       => ['nullable', 'date'],
            'status'          => ['sometimes', 'in:active,on_leave,terminated'],
        ]);

        if (array_key_exists('shift_id', $validated)) {
            if ($validated['shift_id']) {
                $shift = Shift::find($validated['shift_id']);
                $validated['shift_start_time'] = $shift->start_time;
                $validated['shift_end_time'] = $shift->end_time;
            } else {
                $validated['shift_start_time'] = null;
                $validated['shift_end_time'] = null;
            }
        }

        $employee->update($validated);

        $this->logActivity($employee->company_id, 'updated', $employee->id, $validated);

        if ($employee->user_id) {
            Notification::create([
                'user_id'    => $employee->user_id,
                'company_id' => $employee->company_id,
                'type'       => 'employee_profile_updated',
                'title'      => 'Your employee profile was updated',
                'body'       => "Your HR profile ({$employee->employee_code}) was updated.",
                'data'       => ['employee_id' => $employee->id],
            ]);
        }

        return ApiResponse::success($employee, 'Employee updated');
    }

    // Status change only — stays in every list/query (SoftDeletes' default
    // scope only excludes actually-deleted rows). Previously conflated with
    // destroy()'s soft-delete, which made a "deactivated" employee vanish
    // from the Employees list and its own detail page entirely.
    public function deactivate(int $id): JsonResponse
    {
        $employee = $this->employee($id);
        $employee->update(['status' => 'terminated']);

        $this->logActivity($employee->company_id, 'deactivated', $employee->id);

        return ApiResponse::success($employee->fresh(), 'Employee deactivated');
    }

    public function reactivate(int $id): JsonResponse
    {
        $employee = $this->employee($id);
        $employee->update(['status' => 'active']);

        $this->logActivity($employee->company_id, 'reactivated', $employee->id);

        return ApiResponse::success($employee->fresh(), 'Employee reactivated');
    }

    // "Delete" — a distinct, more destructive action from deactivate()
    // above: soft-deletes (matching HR history — attendance/leave/payroll —
    // staying intact rather than hard-deleting), removing the employee from
    // every default query, not just marking them terminated.
    public function destroy(int $id): JsonResponse
    {
        $employee = $this->employee($id);
        $employee->delete();

        $this->logActivity($employee->company_id, 'deleted', $employee->id);

        return ApiResponse::success(null, 'Employee deleted');
    }

    // ── Documents (reuses the existing generic Document model) ────────────

    public function documents(int $id): JsonResponse
    {
        $employee = $this->employee($id);

        $documents = Document::where('linked_to_type', 'Employee')
            ->where('linked_to_id', $employee->id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($documents);
    }

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $employee = $this->employee($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file'  => ['required', 'file', 'max:' . self::MAX_FILE_KB, 'mimes:' . self::ALLOWED_MIMES],
        ]);

        $file = $validated['file'];
        $path = $file->store('companies/' . $employee->company_id . '/employees/' . $employee->id . '/documents');

        $document = Document::create([
            'company_id'      => $employee->company_id,
            'uploaded_by'     => null, // Company Admin actor isn't a User row
            'title'           => $validated['title'],
            'file_path'       => $path,
            'file_name'       => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'linked_to_type'  => 'Employee',
            'linked_to_id'    => $employee->id,
        ]);

        $this->logActivity($employee->company_id, 'document_uploaded', $employee->id, ['document_id' => $document->id]);

        return ApiResponse::success($document, 'Document uploaded', 201);
    }

    public function deleteDocument(int $id, int $documentId): JsonResponse
    {
        $employee = $this->employee($id);

        $document = Document::where('linked_to_type', 'Employee')
            ->where('linked_to_id', $employee->id)
            ->findOrFail($documentId);

        if ($document->file_path) Storage::delete($document->file_path);
        $document->delete();

        $this->logActivity($employee->company_id, 'document_deleted', $employee->id, ['document_id' => $documentId]);

        return ApiResponse::success(null, 'Document deleted');
    }

    // ── Notes ───────────────────────────────────────────────────────────────

    public function notes(int $id): JsonResponse
    {
        $employee = $this->employee($id);

        $notes = EmployeeNote::where('employee_id', $employee->id)
            ->with('authorAdmin:id,name')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($notes);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $employee = $this->employee($id);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $note = EmployeeNote::create([
            'company_id'      => $employee->company_id,
            'employee_id'     => $employee->id,
            'author_admin_id' => $this->admin()->id ?? null,
            'body'            => $validated['body'],
        ]);

        $this->logActivity($employee->company_id, 'note_added', $employee->id, ['note_id' => $note->id]);

        return ApiResponse::success($note->load('authorAdmin:id,name'), 'Note added', 201);
    }
}
