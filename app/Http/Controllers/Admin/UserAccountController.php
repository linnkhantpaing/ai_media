<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCustomerRequest;
use App\Models\Package;
use App\Models\User;
use App\Services\CustomerAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserAccountController extends Controller
{
    public function index(Request $request, CustomerAccounts $accounts): Response|JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', Rule::in(['newest', 'name', 'last_login'])], 'status' => ['nullable', Rule::in(['active', 'suspended', 'archived', 'expired', 'scheduled'])], 'package_id' => ['nullable', 'integer', Rule::exists('packages', 'id')], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])]]);
        $query = User::where('is_admin', false)->with('currentPackageAssignment');
        $status = $filters['status'] ?? '';
        if ($status !== '') {
            $this->filterStatus($query, $status);
        } else {
            $query->where('account_status', '!=', 'archived');
        }
        if ($packageId = $filters['package_id'] ?? null) {
            $query->whereHas('currentPackageAssignment', fn ($assignment) => $assignment->where('package_id', $packageId));
        }
        if ($q = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
        }
        $sort = $filters['sort'] ?? 'newest';
        match ($sort) {
            'name' => $query->orderBy('name'), 'last_login' => $query->orderByDesc('last_login_at'), default => $query->orderByDesc('created_at')
        };
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
        $counts = User::where('is_admin', false)->selectRaw('account_status, count(*) as total')->groupBy('account_status')->pluck('total', 'account_status');
        $activeQuery = User::where('is_admin', false);
        $expiredQuery = User::where('is_admin', false);
        $this->filterStatus($activeQuery, 'active');
        $this->filterStatus($expiredQuery, 'expired');
        $data = ['users' => $page->getCollection()->map(fn (User $user): array => $accounts->resource($user))->all(), 'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()], 'summary' => ['total' => (int) ($counts['active'] ?? 0) + (int) ($counts['suspended'] ?? 0), 'active' => $activeQuery->count(), 'suspended' => (int) ($counts['suspended'] ?? 0), 'archived' => (int) ($counts['archived'] ?? 0), 'expired' => $expiredQuery->count()], 'packageReady' => true, 'packages' => $accounts->packageOptions(), 'packageFilters' => Package::where('published_version', '>', 0)->orderBy('name')->get(['id', 'name', 'code'])->toArray(), 'mailReady' => $accounts->mailReady(), 'timezone' => config('app.timezone', 'UTC'), 'today' => now(config('app.timezone', 'UTC'))->toDateString(), 'filters' => ['package_id' => isset($packageId) ? (string) $packageId : '', 'q' => $q ?? '', 'status' => $status, 'sort' => $sort, 'per_page' => (int) ($filters['per_page'] ?? 10)], 'endpoints' => ['index' => route('admin.users.index', absolute: false), 'login' => route('login'), 'packages' => route('admin.users.package-options', absolute: false)]];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('admin/users', ['accounts' => $data]);
    }

    private function filterStatus(Builder $query, string $status): void
    {
        if ($status === 'expired') {
            $query->where('account_status', 'active')->whereHas('currentPackageAssignment', fn ($assignment) => $assignment->where(fn ($period) => $period->where('ends_at', '<=', now())->orWhere('closed_kind', 'ended')));
        } elseif ($status === 'scheduled') {
            $query->where('account_status', 'active')->whereHas('currentPackageAssignment', fn ($assignment) => $assignment->where('starts_at', '>', now()));
        } elseif ($status === 'active') {
            $query->where('account_status', 'active')->where(fn ($user) => $user->whereDoesntHave('currentPackageAssignment')->orWhereHas('currentPackageAssignment', fn ($assignment) => $assignment->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now())));
        } else {
            $query->where('account_status', $status);
        }
    }

    public function packageOptions(CustomerAccounts $accounts): JsonResponse
    {
        return response()->json([
            'packages' => $accounts->packageOptions(),
            'refreshed_at' => now()->toIso8601String(),
            'today' => now(config('app.timezone', 'UTC'))->toDateString(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(SaveCustomerRequest $request, CustomerAccounts $accounts): JsonResponse
    {
        try {
            $result = $accounts->create($request->user('admin'), $request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
        }

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function show(int $customer, CustomerAccounts $accounts): JsonResponse
    {
        return response()->json($accounts->details($accounts->customer($customer)));
    }

    public function update(SaveCustomerRequest $request, int $customer, CustomerAccounts $accounts): JsonResponse
    {
        try {
            return response()->json($accounts->mutate($request->user('admin'), $customer, $request->validated(), 'edit'));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
        }
    }

    public function sendLoginInformation(Request $request, int $customer, CustomerAccounts $accounts): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1']]);

        return response()->json($accounts->sendLoginInformation($request->user('admin'), $customer, $data));
    }

    public function action(Request $request, int $customer, CustomerAccounts $accounts): JsonResponse
    {
        $accounts->customer($customer);
        $data = $request->validate(['action' => ['required', Rule::in(['reset_password', 'revoke_sessions', 'suspend', 'reactivate', 'archive', 'restore'])], 'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500'], 'is_admin' => ['prohibited']]);
        if ($data['action'] === 'reset_password') {
            $data += Validator::make($request->all(), ['password' => ['required', 'string', 'confirmed', Password::defaults(), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('auth.password_bytes'));
                }
            }]])->validate();
        }

        return response()->json($accounts->mutate($request->user('admin'), $customer, $data, $data['action']));
    }
}
