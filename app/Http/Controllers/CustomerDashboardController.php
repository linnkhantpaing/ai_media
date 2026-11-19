<?php

namespace App\Http\Controllers;

use App\Services\CustomerDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerDashboardController extends Controller
{
    private const LIVE = ['plan', 'actions', 'work', 'recent', 'checklist', 'billing'];

    public function index(Request $request, CustomerDashboard $dashboard): Response
    {
        return Inertia::render('workspace', [
            'dashboard' => $dashboard->build($request->user(), CustomerDashboard::SECTIONS),
            'dashboardLinks' => [
                'live' => route('dashboard.live', absolute: false),
                'usage' => route('dashboard.usage', absolute: false),
                'checklist' => route('dashboard.checklist', absolute: false),
            ],
        ]);
    }

    /** Silent refresh for the live widgets, or a retry of specific sections. */
    public function live(Request $request, CustomerDashboard $dashboard): JsonResponse
    {
        $data = $request->validate(['sections' => ['nullable', 'array'], 'sections.*' => ['string', Rule::in(CustomerDashboard::SECTIONS)], 'range' => ['nullable', Rule::in(['14', '30', 'all'])]]);

        return response()->json($dashboard->build($request->user(), $data['sections'] ?? self::LIVE, $data['range'] ?? '14'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function usage(Request $request, CustomerDashboard $dashboard): JsonResponse
    {
        $data = $request->validate(['range' => ['required', Rule::in(['14', '30', 'all'])]]);

        return response()->json($dashboard->usage($request->user(), $data['range']))->header('Cache-Control', 'private, no-store');
    }

    public function hideChecklist(Request $request, CustomerDashboard $dashboard): JsonResponse
    {
        $dashboard->hideChecklist($request->user());

        return response()->json(['hidden' => true]);
    }
}
