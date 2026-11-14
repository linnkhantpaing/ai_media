<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboard $dashboard): Response|JsonResponse
    {
        $input = $request->validate([
            'dates' => ['nullable', Rule::in(['1', '7', '30', 'month', 'last_month', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'currency' => ['nullable', Rule::in(['MMK', 'THB', 'USD'])],
        ]);
        $filters = array_replace(['dates' => '30', 'from' => '', 'to' => '', 'currency' => 'MMK'], array_filter($input, fn ($value) => $value !== null));
        $data = $dashboard->overview($filters);

        return $request->expectsJson()
            ? response()->json($data)->header('Cache-Control', 'no-store, private')
            : Inertia::render('admin/dashboard', ['dashboard' => $data]);
    }
}
