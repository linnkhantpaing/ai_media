<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodVersion;
use App\Services\PaymentConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentSettingsController extends Controller
{
    public function index(Request $request, PaymentConfiguration $configuration): Response|JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'currency' => ['nullable', Rule::in(PaymentConfiguration::CURRENCIES)], 'type' => ['nullable', Rule::in(['wallet', 'bank', 'other'])], 'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', Rule::in([10, 20, 50])]]);
        $settings = $configuration->settings();
        $query = PaymentMethod::query();
        if ($status = $filters['status'] ?? '') {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'archived');
        }
        foreach (['currency', 'type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('provider', 'like', '%'.$search.'%')->orWhere('internal_label', 'like', '%'.$search.'%'));
        }
        $page = $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10));
        $all = PaymentMethod::where('status', '!=', 'archived')->orderBy('sort_order')->orderBy('name')->get();
        $summary = [];
        foreach (PaymentConfiguration::CURRENCIES as $code) {
            $group = $all->where('currency', $code);
            $summary[$code] = ['total' => $group->count(), 'active' => $group->where('status', 'active')->count(), 'default_name' => $all->firstWhere('id', $settings['currencies'][$code]['default_id'])?->name];
        }
        $data = [
            'settings' => $settings, 'summary' => $summary,
            'methods' => $page->getCollection()->map(fn ($method) => $configuration->resource($method, $settings))->all(),
            'options' => $all->map(fn ($method) => ['id' => $method->id, 'name' => $method->name, 'currency' => $method->currency, 'status' => $method->status, 'ready' => $configuration->ready($method)])->all(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'filters' => ['q' => $search, 'currency' => $filters['currency'] ?? '', 'type' => $filters['type'] ?? '', 'status' => $status, 'per_page' => (int) ($filters['per_page'] ?? 10)],
            'timezone' => config('app.timezone'),
            'endpoints' => ['index' => route('admin.settings.payments', absolute: false), 'store' => route('admin.settings.payments.store', absolute: false), 'currencies' => route('admin.settings.payments.currencies', absolute: false), 'rules' => route('admin.settings.payments.rules', absolute: false)],
        ];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : Inertia::render('admin/payment-settings', ['payments' => $data]);
    }

    public function show(int $method, PaymentConfiguration $configuration): JsonResponse
    {
        return response()->json($configuration->details(PaymentMethod::findOrFail($method)))->header('Cache-Control', 'no-store');
    }

    public function store(SavePaymentMethodRequest $request, PaymentConfiguration $configuration): JsonResponse
    {
        $result = $configuration->saveMethod($request->user('admin'), $request->validated());

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function update(SavePaymentMethodRequest $request, int $method, PaymentConfiguration $configuration): JsonResponse
    {
        return response()->json($configuration->saveMethod($request->user('admin'), $request->validated(), $method));
    }

    public function action(Request $request, int $method, PaymentConfiguration $configuration): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['default', 'activate', 'deactivate', 'archive', 'restore'])],
            'revision' => ['required', 'integer', 'min:1'], 'config_revision' => ['required', 'integer', 'min:0'],
            'request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500', 'not_regex:/^\s*$/u'],
            'replacement_id' => ['nullable', 'integer', 'exists:payment_methods,id'], 'disable_currency' => ['sometimes', 'boolean'],
            'enable_currency' => ['sometimes', 'boolean'], 'make_default' => ['sometimes', 'boolean'],
        ]);

        return response()->json($configuration->methodAction($request->user('admin'), $method, $data));
    }

    public function currencies(Request $request, PaymentConfiguration $configuration): JsonResponse
    {
        $rules = ['currencies' => ['required', 'array:MMK,THB,USD'], 'config_revision' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:500', 'not_regex:/^\s*$/u']];
        foreach (PaymentConfiguration::CURRENCIES as $code) {
            $rules['currencies.'.$code] = ['required', 'array:enabled,default_id'];
            $rules['currencies.'.$code.'.enabled'] = ['required', 'boolean'];
            $rules['currencies.'.$code.'.default_id'] = ['present', 'nullable', 'integer', 'exists:payment_methods,id'];
        }

        return response()->json($configuration->saveCurrencies($request->user('admin'), $request->validate($rules)));
    }

    public function rules(Request $request, PaymentConfiguration $configuration): JsonResponse
    {
        $rules = ['config_revision' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'], 'rules' => ['required', 'array:evidence_required,reference_required,sender_required,transfer_date_required,expiry_hours,instructions'], 'rules.expiry_hours' => ['required', 'integer', Rule::in([1, 6, 24, 48])], 'rules.instructions' => ['required', 'array:my,en,th']];
        foreach (['evidence_required', 'reference_required', 'sender_required', 'transfer_date_required'] as $field) {
            $rules['rules.'.$field] = ['required', 'boolean'];
        }
        foreach (['my', 'en', 'th'] as $locale) {
            $rules['rules.instructions.'.$locale] = ['present', 'nullable', 'string', 'max:1500'];
        }

        return response()->json($configuration->saveRules($request->user('admin'), $request->validate($rules)));
    }

    public function qr(int $method, int $version): HttpResponse
    {
        $snapshot = PaymentMethodVersion::where('payment_method_id', $method)->where('number', $version)->firstOrFail();
        $path = $snapshot->definition['qr_path'] ?? null;
        abort_unless(is_string($path) && preg_match('/^payment-qr\/[a-f0-9-]+\.(?:png|jpe?g|webp)$/D', $path) && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => Storage::disk('local')->mimeType($path), 'Content-Disposition' => 'inline; filename="payment-qr.'.pathinfo($path, PATHINFO_EXTENSION).'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
