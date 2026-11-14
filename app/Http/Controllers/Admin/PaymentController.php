<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentMethod;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentConfiguration;
use App\Services\Payments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentController extends Controller
{
    public function index(Request $request, Payments $payments): Response|JsonResponse
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tab' => ['nullable', Rule::in(['all', 'review', 'approved', 'closed', 'refunds'])], 'currency' => ['nullable', Rule::in(PaymentConfiguration::CURRENCIES)], 'method' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(Payments::STATUSES)], 'refund_status' => ['nullable', Rule::in(['pending', 'completed', 'rejected', 'cancelled'])], 'purpose' => ['nullable', Rule::in(['new', 'renewal', 'change'])], 'dates' => ['nullable', Rule::in(['all', '7', '30', 'custom'])], 'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d'], 'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from'], 'sort' => ['nullable', Rule::in(['newest', 'oldest', 'waiting'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', Rule::in([10, 20, 50])]]);
        $base = PaymentTransaction::query();
        $search = trim($v['q'] ?? '');
        if ($search !== '') {
            $id = preg_replace('/^(?:PYT-)?0*/i', '', $search);
            $base->where(fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))->when(ctype_digit($id), fn ($q) => $q->orWhereKey((int) $id))->orWhereIn('id', $this->referenceMatches($search)));
        }
        foreach (['currency' => 'currency', 'method' => 'payment_method_id', 'purpose' => 'purpose'] as $key => $column) {
            if (filled($v[$key] ?? null)) {
                $base->where($column, $v[$key]);
            }
        }
        $dates = $v['dates'] ?? 'all';
        if (in_array($dates, ['7', '30'], true)) {
            $base->where('created_at', '>=', now()->subDays((int) $dates));
        } elseif ($dates === 'custom') {
            $base->where('created_at', '>=', CarbonImmutable::parse($v['from'], config('app.timezone'))->startOfDay())->where('created_at', '<=', CarbonImmutable::parse($v['to'], config('app.timezone'))->endOfDay());
        }
        $summary = ['all' => (clone $base)->count(), 'review' => (clone $base)->whereIn('status', ['pending_review', 'under_review', 'needs_information'])->count(), 'approved' => (clone $base)->where('status', 'approved')->count(),
            'closed' => (clone $base)->where(fn ($q) => $q->whereIn('status', ['rejected', 'cancelled', 'expired'])->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('expires_at', '<=', now())))->count(),
            'refunds' => (clone $base)->whereHas('refunds')->count()];
        $refundQuery = PaymentRefund::whereIn('payment_transaction_id', (clone $base)->select('id'));
        $summary['pending_refunds'] = (clone $refundQuery)->whereIn('status', ['requested', 'under_review'])->count();
        $summary['completed_refunds'] = (clone $refundQuery)->where('status', 'completed')->count();
        $amounts = [];
        foreach (PaymentConfiguration::CURRENCIES as $code) {
            $ids = (clone $base)->where('currency', $code)->select('id');
            $amounts[$code] = ['approved' => (int) (clone $base)->where('status', 'approved')->where('currency', $code)->sum('expected_minor'), 'refunded' => (int) (clone $refundQuery)->where('status', 'completed')->whereIn('payment_transaction_id', $ids)->sum('amount_minor')];
        }
        $query = clone $base;
        $tab = $v['tab'] ?? 'all';
        match ($tab) {
            'review' => $query->whereIn('status', ['pending_review', 'under_review', 'needs_information']),'approved' => $query->where('status', 'approved'),'refunds' => $query->whereHas('refunds'),'closed' => $query->where(fn ($q) => $q->whereIn('status', ['rejected', 'cancelled', 'expired'])->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('expires_at', '<=', now()))),default => null
        };
        if ($status = $v['status'] ?? '') {
            if ($status === 'expired') {
                $query->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('expires_at', '<=', now())));
            } else {
                $query->where('status', $status);
                if ($status === 'awaiting_payment') {
                    $query->where('expires_at', '>', now());
                }
            }
        }

        if (filled($v['refund_status'] ?? null)) {
            $refundStates = $v['refund_status'] === 'pending' ? ['requested', 'under_review'] : [$v['refund_status']];
            $query->whereHas('refunds', fn ($refunds) => $refunds->whereIn('status', $refundStates));
        }
        $sort = $v['sort'] ?? 'newest';
        if ($sort === 'waiting') {
            $query->orderByRaw('CASE WHEN status IN (?, ?, ?) OR (status = ? AND expires_at > ?) THEN 0 ELSE 1 END', ['pending_review', 'under_review', 'needs_information', 'awaiting_payment', now()])
                ->orderByRaw('COALESCE(submitted_at, created_at) ASC');
        }
        $query->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc')->orderBy('id', $sort === 'newest' ? 'desc' : 'asc');
        $page = $query->with(['user', 'refunds'])->paginate((int) ($v['per_page'] ?? 10));
        $data = ['items' => $page->getCollection()->map(fn ($p) => $payments->present($p))->all(), 'summary' => $summary, 'amounts' => $amounts, 'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'currency'])->toArray(), 'timezone' => config('app.timezone'), 'today' => now(config('app.timezone'))->toDateString(),
            'filters' => ['refund_status' => $v['refund_status'] ?? '', 'q' => $search, 'tab' => $tab, 'currency' => $v['currency'] ?? '', 'method' => (string) ($v['method'] ?? ''), 'status' => $status, 'purpose' => $v['purpose'] ?? '', 'dates' => $dates, 'from' => $v['from'] ?? '', 'to' => $v['to'] ?? '', 'sort' => $sort, 'per_page' => (int) ($v['per_page'] ?? 10)],
            'endpoints' => ['index' => route('admin.payments.index', absolute: false), 'store' => route('admin.payments.store', absolute: false), 'options' => route('admin.payments.options', absolute: false), 'subscriptions' => route('admin.subscriptions.index', absolute: false), 'configuration' => route('admin.settings.payments', absolute: false)]];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : Inertia::render('admin/payments', ['transactions' => $data]);
    }

    private function referenceMatches(string $search): array
    {
        $reference = mb_strtolower(preg_replace('/\s+/u', '', trim($search)));

        return PaymentTransaction::where('reference_lookup', hash_hmac('sha256', $reference, (string) config('app.key')))->pluck('id')->all();
    }

    public function options(Request $request, PaymentConfiguration $configuration, Payments $payments): JsonResponse
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'payment_id' => ['nullable', 'integer', 'exists:payment_transactions,id']]);
        $editing = isset($v['payment_id']) ? PaymentTransaction::findOrFail($v['payment_id']) : null;
        $search = trim($v['q'] ?? '');
        $customers = User::where('is_admin', false)->where('account_status', 'active')->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))->orderBy('name')->limit(30)->get();
        if ($editing && ! $customers->contains('id', $editing->user_id)) {
            $customers->prepend(User::findOrFail($editing->user_id));
        }
        $settings = $configuration->settings();
        $methods = PaymentMethod::where('status', 'active')->get()->filter(fn ($m) => $settings['currencies'][$m->currency]['enabled'] && $configuration->ready($m));
        $packages = Package::where('status', 'active')->where('type', 'paid')->where('published_version', '>', 0)->orderBy('name')->get()->map(function ($p) use ($methods) {
            $v = PackageVersion::where('package_id', $p->id)->where('number', $p->published_version)->firstOrFail();

            return ['id' => $p->id, 'name' => $p->name, 'version' => $v->number, 'currency' => $v->definition['currency'], 'price_minor' => $v->definition['price_minor'], 'validity_days' => $v->definition['validity_days'], 'available' => $methods->contains('currency', $v->definition['currency'])];
        })->all();

        if ($editing) {
            $snapshot = $editing->package_snapshot;
            $packages = array_values(array_filter($packages, fn ($p) => $p['id'] !== (int) $snapshot['id']));
            array_unshift($packages, ['id' => (int) $snapshot['id'], 'name' => $snapshot['name'], 'version' => (int) $snapshot['version'], 'currency' => $editing->currency,
                'price_minor' => $editing->expected_minor, 'validity_days' => (int) $snapshot['definition']['validity_days'], 'available' => true]);
        }
        $methodOptions = $methods->map(fn ($m) => $configuration->resource($m, $settings))->values();
        if ($editing) {
            $methodOptions = $methodOptions->reject(fn ($m) => $m['id'] === $editing->payment_method_id)->values();
            $methodOptions->prepend([...$payments->present($editing)['method'], 'currency' => $editing->currency]);
        }

        return response()->json(['customers' => $customers->map(function ($u) use ($packages, $payments) {
            $context = $payments->recordingContext($u);
            $a = $context['active'] ?? CustomerPackageAssignment::current()->where('user_id', $u->id)->first();
            $purposes = [];
            foreach ($packages as $package) {
                $purposes[$package['id']] = $payments->recordingPurposes($context, $package['id'], $package['version']);
            }

            return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'revision' => $u->account_revision, 'scheduled' => $context['scheduled'], 'purposes' => (object) $purposes, 'current' => $a ? ['id' => $a->id, 'name' => $a->effectiveSnapshot()['name'], 'status' => $a->status(), 'ends_at' => $a->ends_at->toIso8601String()] : null];
        })->all(), 'packages' => $packages, 'methods' => $methodOptions->all(), 'config_revision' => $settings['revision'], 'rules' => $settings['rules']])->header('Cache-Control', 'no-store');
    }

    public function store(RecordPaymentRequest $request, Payments $payments): JsonResponse
    {
        $result = $payments->create($request->user('admin'), $request->validated());

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function update(RecordPaymentRequest $request, int $payment, Payments $payments): JsonResponse
    {
        return response()->json($payments->update($request->user('admin'), $payment, $request->validated()));
    }

    public function show(int $payment, Payments $payments): JsonResponse
    {
        return response()->json($payments->details(PaymentTransaction::findOrFail($payment)))->header('Cache-Control', 'no-store');
    }

    public function action(Request $request, int $payment, Payments $payments): JsonResponse
    {
        if (is_string($request->input('verified'))) {
            $verified = json_decode($request->input('verified'), true);
            if (is_array($verified)) {
                $request->merge(['verified' => $verified]);
            }
        }
        $rules = ['action' => ['required', Rule::in(['review', 'approve', 'information', 'reject', 'cancel', 'evidence', 'refund', 'refund_review', 'refund_complete', 'refund_reject', 'refund_cancel'])], 'revision' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'reason' => ['required_unless:action,review,approve,evidence', 'nullable', 'string', 'max:500', 'not_regex:/^\s*$/u'], 'customer_revision' => ['required_if:action,approve', 'integer', 'min:1'], 'verified' => ['required_if:action,approve', 'array:account,amount,reference,outcome'],
            'refund_id' => ['required_if:action,refund_review,refund_complete,refund_reject,refund_cancel', 'integer'], 'amount' => ['required_if:action,refund', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'destination' => ['required_if:action,refund', 'string', 'max:1000'], 'access_action' => ['sometimes', Rule::in(['keep', 'end', 'cancel'])], 'confirmed' => ['sometimes', 'boolean'],
            'received' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'], 'reference' => ['nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/u'], 'sender' => ['nullable', 'string', 'max:150'], 'transfer_date' => ['nullable', 'date', 'before_or_equal:now'], 'receipt' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:10240', Rule::when($request->file('receipt') instanceof UploadedFile && str_starts_with((string) $request->file('receipt')->getMimeType(), 'image/'), ['image', 'dimensions:max_width=6000,max_height=6000'])]];
        foreach (['account', 'amount', 'reference', 'outcome'] as $key) {
            $rules['verified.'.$key] = ['required_if:action,approve', 'boolean'];
        }

        return response()->json($payments->act($request->user('admin'), $payment, $request->validate($rules)));
    }

    public function receipt(int $payment, string $receipt): HttpResponse
    {
        $file = PaymentReceipt::where('payment_transaction_id', $payment)->findOrFail($receipt);
        abort_unless(preg_match('/^payment-receipts\/[a-f0-9-]+\.(?:png|jpe?g|webp|pdf)$/D', $file->path) && Storage::disk('local')->exists($file->path), 404);
        $download = request()->boolean('download') || $file->mime === 'application/pdf';

        return response(Storage::disk('local')->get($file->path), 200, ['Content-Type' => $file->mime, 'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="receipt.'.pathinfo($file->path, PATHINFO_EXTENSION).'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
