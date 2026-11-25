<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePackageRequest;
use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Services\PackageCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function index(Request $request, PackageCatalog $catalog): Response|JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(['paid', 'trial', 'topup'])], 'status' => ['nullable', Rule::in(['draft', 'active', 'inactive', 'archived'])], 'sort' => ['nullable', Rule::in(['newest', 'name', 'updated'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])]]);
        $query = Package::query();
        $status = $filters['status'] ?? '';
        if ($status) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'archived');
        }
        if ($type = $filters['type'] ?? '') {
            $query->where('type', $type);
        }
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        $sort = $filters['sort'] ?? 'newest';
        match ($sort) {
            'name' => $query->orderBy('name'),'updated' => $query->orderByDesc('updated_at'),default => $query->orderByDesc('created_at')
        };
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
        $counts = Package::selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $data = ['packages' => $page->getCollection()->map(fn (Package $package): array => $catalog->resource($package))->all(), 'summary' => ['total' => (int) ($counts['active'] ?? 0) + (int) ($counts['inactive'] ?? 0) + (int) ($counts['draft'] ?? 0), 'active' => (int) ($counts['active'] ?? 0), 'draft' => (int) ($counts['draft'] ?? 0), 'assigned' => CustomerPackageAssignment::current()->whereHas('user', fn ($user) => $user->where('is_admin', false)->where('account_status', '!=', 'archived'))->distinct()->count('user_id')], 'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()], 'filters' => ['q' => $search, 'type' => $type, 'status' => $status, 'sort' => $sort, 'per_page' => (int) ($filters['per_page'] ?? 10)], 'capabilities' => $catalog->capabilities(), 'timezone' => config('app.timezone', 'UTC'), 'endpoints' => ['index' => route('admin.packages.index', absolute: false)]];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('admin/packages', ['catalog' => $data]);
    }

    public function store(SavePackageRequest $request, PackageCatalog $catalog): JsonResponse
    {
        try {
            $result = $catalog->save($request->user('admin'), $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Package name or code is already in use.']);
        }

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function update(SavePackageRequest $request, int $package, PackageCatalog $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->save($request->user('admin'), $request->validated(), $package));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Package name or code is already in use.']);
        }
    }

    public function show(int $package, PackageCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->details(Package::findOrFail($package)));
    }

    public function action(Request $request, int $package, PackageCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['publish', 'activate', 'deactivate', 'archive', 'restore'])], 'revision' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500']]);

        return response()->json($catalog->action($request->user('admin'), $data['request_id'], [['id' => $package, 'revision' => $data['revision']]], $data['action'], $data['reason']));
    }

    public function bulk(Request $request, PackageCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['activate', 'deactivate', 'archive'])], 'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*' => ['required', 'array:id,revision'], 'items.*.id' => ['required', 'integer', 'distinct', 'min:1'], 'items.*.revision' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500']]);

        return response()->json($catalog->action($request->user('admin'), $data['request_id'], $data['items'], $data['action'], $data['reason']));
    }
}
