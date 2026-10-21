<?php

namespace App\Http\Controllers;

use App\Models\CareerApplication;
use App\Models\CareerPosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CareerPositionController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $positions = CareerPosition::query()
            ->where('status', CareerPosition::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $positions,
        ]);
    }

    public function publicShow(string $uuid): JsonResponse
    {
        $position = CareerPosition::query()
            ->where('uuid', $uuid)
            ->where('status', CareerPosition::STATUS_PUBLISHED)
            ->first();

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $position,
        ]);
    }

    /** Customer (logged-in) submits an application for a published position. */
    public function apply(Request $request, string $uuid): JsonResponse
    {
        $position = CareerPosition::query()
            ->where('uuid', $uuid)
            ->where('status', CareerPosition::STATUS_PUBLISHED)
            ->first();

        if (!$position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found or no longer accepting applications',
            ], 404);
        }

        $user = $request->user();

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'salary_expectation' => ['required', 'string', 'max:255'],
            'resume_url' => ['nullable', 'string', 'max:1000'],
            'resume_file' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ], [
            'salary_expectation.required' => 'Please write down your salary expectation.',
            'resume_file.mimes' => 'Resume must be a PDF, DOC, or DOCX file.',
            'resume_file.max' => 'Resume must be less than 5MB.',
        ]);

        $existing = CareerApplication::query()
            ->where('career_position_uuid', $position->uuid)
            ->where('user_uuid', $user->uuid)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied for this position.',
                'data' => $existing,
            ], 409);
        }

        $resumeUrl = $validated['resume_url'] ?? null;
        $resumeFileName = null;
        if ($request->hasFile('resume_file')) {
            $file = $request->file('resume_file');
            $resumeFileName = $file->getClientOriginalName();
            $path = $file->store('career-resumes', 'public');
            $resumeUrl = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : $resumeUrl;
        }
        unset($validated['resume_file']);

        $application = CareerApplication::query()->create([
            ...$validated,
            'resume_url' => $resumeUrl,
            'resume_file_name' => $resumeFileName,
            'career_position_uuid' => $position->uuid,
            'user_uuid' => $user->uuid,
            'status' => 'applied',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully.',
            'data' => $application,
        ], 201);
    }

    /** Customer lists all of their own applications with statuses. */
    public function myApplications(Request $request): JsonResponse
    {
        $applications = CareerApplication::query()
            ->with(['position:uuid,title,department,location,work_setup,employment_type,status'])
            ->where('user_uuid', $request->user()->uuid)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    /** Customer checks whether they already applied for a position. */
    public function myApplication(Request $request, string $uuid): JsonResponse
    {
        $application = CareerApplication::query()
            ->where('career_position_uuid', $uuid)
            ->where('user_uuid', $request->user()->uuid)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $application,
            'applied' => $application !== null,
        ]);
    }

    public function indexApplications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(CareerApplication::STATUSES)],
            'career_position_uuid' => ['nullable', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 20)));

        $paginator = CareerApplication::query()
            ->with(['position:uuid,title,department,location,work_setup,employment_type'])
            ->when(!empty($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(!empty($validated['career_position_uuid']), fn ($q) => $q->where('career_position_uuid', $validated['career_position_uuid']))
            ->when(!empty($validated['q']), function ($q) use ($validated) {
                $keyword = $validated['q'];
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('full_name', 'LIKE', "%{$keyword}%")
                        ->orWhere('email', 'LIKE', "%{$keyword}%")
                        ->orWhereHas('position', fn ($p) => $p->where('title', 'LIKE', "%{$keyword}%"));
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($paginator);
    }

    public function updateApplicationStatus(Request $request, string $uuid): JsonResponse
    {
        $application = CareerApplication::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in(CareerApplication::STATUSES)],
            'interview_at' => ['required_if:status,for_interview', 'nullable', 'date'],
            'interview_location' => ['required_if:status,for_interview', 'nullable', 'string', 'max:500'],
        ], [
            'interview_at.required_if' => 'Please set the date and time of the interview.',
            'interview_location.required_if' => 'Please indicate where the interview will be held.',
        ]);

        $application->update([
            'status' => $validated['status'],
            'interview_at' => $validated['interview_at'] ?? $application->interview_at,
            'interview_location' => $validated['interview_location'] ?? $application->interview_location,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application status updated.',
            'data' => $application->fresh()->load('position:uuid,title,department,location,work_setup,employment_type'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([CareerPosition::STATUS_DRAFT, CareerPosition::STATUS_PUBLISHED])],
            'work_setup' => ['nullable', Rule::in(CareerPosition::WORK_SETUPS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 20)));

        $paginator = CareerPosition::query()
            ->filters($validated)
            ->withCount('applications')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($perPage);

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePosition($request);

        $position = CareerPosition::query()->create([
            ...$validated,
            'created_by' => auth('admin')->user()?->uuid,
            'updated_by' => auth('admin')->user()?->uuid,
        ]);

        return response()->json(['data' => $position], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $position = CareerPosition::query()
            ->where('uuid', $uuid)
            ->withCount('applications')
            ->firstOrFail();

        return response()->json(['data' => $position]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $position = CareerPosition::query()->where('uuid', $uuid)->firstOrFail();
        $validated = $this->validatePosition($request);

        $position->update([
            ...$validated,
            'updated_by' => auth('admin')->user()?->uuid,
        ]);

        return response()->json(['data' => $position->fresh()]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $position = CareerPosition::query()->where('uuid', $uuid)->firstOrFail();
        $position->delete();

        return response()->json([
            'success' => true,
            'message' => 'Position deleted successfully.',
        ]);
    }

    private function validatePosition(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'work_setup' => ['required', Rule::in(CareerPosition::WORK_SETUPS)],
            'employment_type' => ['required', Rule::in(CareerPosition::EMPLOYMENT_TYPES)],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            'status' => ['required', Rule::in([CareerPosition::STATUS_DRAFT, CareerPosition::STATUS_PUBLISHED])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
